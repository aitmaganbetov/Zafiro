<?php

namespace App\Services;

use App\Models\CashShift;
use App\Models\CashTransaction;
use App\Models\ClubComputer;
use App\Models\ClubZone;
use App\Models\Tariff;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Time sold at the cash register to a guest without an account: a tariff (its price, no
 * discount) or minutes at the zone's hourly price, on each selected PC. Paid into the open
 * cash shift; the PC agent unlocks without a sign-in until the time is over.
 */
class GuestSessions
{
    public const PAYMENT_METHODS = ['cash', 'card', 'qr'];

    public function __construct(
        private ActivityLogger $activity,
        private ComputerReservations $reservations,
        private AgentTariffPurchaseService $tariffs,
    ) {}

    /**
     * @param  list<int>  $computerIds
     * @return array{computers: Collection<int, ClubComputer>, amount: string, minutes: int}
     */
    public function sell(array $computerIds, ?int $tariffId, ?int $minutes, string $paymentMethod, int $cashierId): array
    {
        return DB::transaction(function () use ($computerIds, $tariffId, $minutes, $paymentMethod, $cashierId): array {
            $shift = CashShift::query()->where('status', CashShift::STATUS_OPEN)->lockForUpdate()->first();
            if ($shift === null) {
                throw ValidationException::withMessages(['payment_method' => __('Сначала откройте смену в кассе.')]);
            }

            $tariff = $tariffId === null ? null : Tariff::query()->with('zones:id')->where('is_active', true)->find($tariffId);
            if ($tariffId !== null && $tariff === null) {
                throw ValidationException::withMessages(['tariff_id' => __('Тариф недоступен.')]);
            }
            $duration = $tariff !== null ? $this->tariffs->effectiveMinutes($tariff) : (int) $minutes;

            $computers = ClubComputer::query()->whereKey($computerIds)->orderBy('id')->lockForUpdate()->get();
            if ($computers->count() !== count(array_unique($computerIds))) {
                throw ValidationException::withMessages(['computer_ids' => __('Выберите ПК на карте клуба.')]);
            }
            $zones = ClubZone::query()->whereKey($computers->pluck('club_zone_id')->unique())->get(['id', 'name', 'price_per_hour'])->keyBy('id');

            $total = 0;
            $now = now();
            foreach ($computers as $computer) {
                $price = $this->priceFor($computer, $tariff, $duration, $zones->get($computer->club_zone_id));
                $extend = $computer->hasPaidSession();
                if ($computer->is_reserved || ($extend && ! $computer->session_guest)) {
                    throw ValidationException::withMessages(['computer_ids' => __('ПК :computer занят или забронирован.', ['computer' => $computer->displayName()])]);
                }

                $endsAt = ($extend ? $computer->session_ends_at->copy() : $now->copy())->addMinutes($duration);
                // No customer sits on a booking; the time must end before someone else's booking.
                $booking = $this->reservations->blocking($computer, 0, $endsAt);
                if ($booking !== null) {
                    throw ValidationException::withMessages(['computer_ids' => __('ПК :computer забронирован с :time.', ['computer' => $computer->displayName(), 'time' => $booking->starts_at->format('H:i')])]);
                }

                $computer->forceFill([
                    'current_session_id' => $extend ? $computer->current_session_id : (string) Str::uuid(),
                    'current_session_user_id' => null,
                    'session_guest' => true,
                    'session_ends_at' => $endsAt,
                ])->save();
                $total += $price;
            }

            $names = $computers->map->displayName()->implode(', ');
            $what = $tariff !== null ? __('тариф «:tariff»', ['tariff' => $tariff->name]) : trans_choice(':count минута|:count минуты|:count минут', $duration);
            $payment = [__('наличные'), __('карта'), __('QR-код')][array_search($paymentMethod, self::PAYMENT_METHODS, true)];
            $amount = AgentTariffPurchaseService::formatCents($total);
            $transaction = $shift->transactions()->create([
                'user_id' => $cashierId,
                'type' => CashTransaction::TYPE_INCOME,
                'amount' => $amount,
                'description' => __('Гостевая сессия: :what, ПК :computers (:payment)', ['what' => $what, 'computers' => $names, 'payment' => $payment]),
            ]);
            $details = ['computers' => $names, 'minutes' => $duration, 'amount' => $amount, 'payment_method' => $payment, 'tariff' => $tariff?->name ?? '—'];
            $this->activity->record('cash.income', $transaction->description, $cashierId, $transaction, $details);
            $this->activity->record('computer.guest_session', $transaction->description, $cashierId, null, $details);

            return ['computers' => $computers, 'amount' => $amount, 'minutes' => $duration];
        });
    }

    private function priceFor(ClubComputer $computer, ?Tariff $tariff, int $minutes, ?ClubZone $zone): int
    {
        if ($tariff !== null) {
            if ($tariff->zones->isNotEmpty() && ! $tariff->zones->contains('id', $computer->club_zone_id)) {
                throw ValidationException::withMessages(['tariff_id' => __('Тариф не действует в зоне ПК :computer.', ['computer' => $computer->displayName()])]);
            }

            return AgentTariffPurchaseService::amountToCents((string) $tariff->price);
        }

        if ($zone?->price_per_hour === null) {
            throw ValidationException::withMessages(['minutes' => __('В зоне ПК :computer время поминутно не продаётся.', ['computer' => $computer->displayName()])]);
        }

        return AgentTariffPurchaseService::minutesPriceInCents((string) $zone->price_per_hour, $minutes);
    }
}
