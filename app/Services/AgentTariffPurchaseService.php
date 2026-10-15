<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ClubComputer;
use App\Models\ClubMembership;
use App\Models\MemberBalance;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AgentTariffPurchaseService
{
    public function __construct(private ActivityLogger $activity, private CurrentClub $currentClub, private ComputerReservations $reservations) {}

    /** @return Collection<int, array<string, mixed>> */
    public function availableFor(User $user): Collection
    {
        $membership = $user->currentMembership()->firstOrFail();

        $tariffs = Tariff::query()->where('is_active', true)
            ->with('zones')
            ->orderBy('duration_minutes')
            ->orderBy('name')
            ->get();
        $purchases = ActivityLog::query()
            ->where('event', 'tariff.purchased')
            ->where('subject_type', (new Tariff)->getMorphClass())
            ->whereIn('subject_id', $tariffs->modelKeys())
            ->selectRaw('subject_id, COUNT(*) as purchases_count')
            ->groupBy('subject_id')
            ->pluck('purchases_count', 'subject_id');

        return $tariffs
            ->filter(fn (Tariff $tariff): bool => $this->isAvailable($tariff, $membership, (int) $purchases->get($tariff->getKey(), 0)))
            ->map(fn (Tariff $tariff): array => $this->representation($tariff, $membership))
            ->values();
    }

    /** @return array<string, mixed> */
    public function purchase(User $user, Tariff $tariff, int $computerId): array
    {
        return DB::transaction(function () use ($user, $tariff, $computerId): array {
            $tariff = Tariff::query()->lockForUpdate()->findOrFail($tariff->getKey());
            $membership = $user->currentMembership()->lockForUpdate()->firstOrFail();
            $computer = ClubComputer::query()->lockForUpdate()->findOrFail($computerId);

            if (! $this->isAvailable($tariff, $membership, $this->purchaseCount($tariff))) {
                throw ValidationException::withMessages(['tariff' => __('Тариф недоступен.')]);
            }

            if ($computer->is_reserved) {
                throw ValidationException::withMessages(['computer_id' => __('Забронированный компьютер нельзя использовать для сессии.')]);
            }

            $hasActiveSession = $computer->current_session_id !== null
                && $computer->session_ends_at?->isFuture() === true
                && $computer->current_session_user_id !== null;
            if ($hasActiveSession && $computer->current_session_user_id !== $user->getKey()) {
                throw ValidationException::withMessages(['computer_id' => __('На компьютере уже идёт сессия другого пользователя.')]);
            }

            if ($tariff->zones()->exists() && ! $tariff->zones()->whereKey($computer->club_zone_id)->exists()) {
                throw ValidationException::withMessages(['tariff' => __('Тариф недоступен для этого компьютера.')]);
            }

            $balance = MemberBalance::query()->where('user_id', $user->getKey())->lockForUpdate()->firstOrFail();
            $price = $this->priceInCents($tariff, $membership);
            $mainBalance = self::amountToCents((string) $balance->balance);
            $bonusBalance = self::amountToCents((string) $balance->bonus_balance);
            $bonusPayment = 0;

            if ($tariff->payment_method === 'main_balance_only') {
                if ($mainBalance < $price) {
                    throw ValidationException::withMessages(['balance' => __('Недостаточно средств для покупки тарифа.')]);
                }
                $mainBalance -= $price;
            } else {
                $mainPayment = min($mainBalance, $price);
                $bonusPayment = $price - $mainPayment;
                if ($bonusBalance < $bonusPayment) {
                    throw ValidationException::withMessages(['balance' => __('Недостаточно средств для покупки тарифа.')]);
                }
                $mainBalance -= $mainPayment;
                $bonusBalance -= $bonusPayment;
            }

            $minutes = $this->effectiveMinutes($tariff);
            $now = now();
            $sessionId = $hasActiveSession ? $computer->current_session_id : (string) Str::uuid();
            $sessionEndsAt = ($hasActiveSession ? $computer->session_ends_at->copy() : $now->copy())
                ->addMinutes($minutes);

            // Time that runs into someone else's booking is not sold; the booked customer may play.
            $booking = $this->reservations->blocking($computer, $user->getKey(), $sessionEndsAt);
            if ($booking !== null) {
                throw ValidationException::withMessages(['computer_id' => __('ПК забронирован с :time. Выберите тариф короче или другой ПК.', ['time' => $booking->starts_at->format('H:i')])]);
            }
            $this->reservations->markArrivedBy($computer, $user->getKey(), $sessionEndsAt);

            $balance->update([
                'balance' => self::formatCents($mainBalance),
                'bonus_balance' => self::formatCents($bonusBalance),
            ]);
            $computer->update([
                'current_session_id' => $sessionId,
                'current_session_user_id' => $user->getKey(),
                'session_ends_at' => $sessionEndsAt,
            ]);
            $this->activity->record('tariff.purchased', __('Куплен тариф: :tariff', ['tariff' => $tariff->name]), $user->getKey(), $tariff, [
                'amount' => self::formatCents($price),
                'currency' => $tariff->currency,
                'main_balance_amount' => self::formatCents($price - $bonusPayment),
                'bonus_balance_amount' => self::formatCents($bonusPayment),
                'duration_minutes' => $minutes,
                'computer_id' => $computer->getKey(),
                'session_id' => $sessionId,
                'session_ends_at' => $sessionEndsAt->toIso8601String(),
            ]);

            return [
                'success' => true,
                'message' => __('Тариф подключён.'),
                'new_balance' => self::formatCents($mainBalance),
                'new_bonus' => self::formatCents($bonusBalance),
                'minutes_added' => $minutes,
                'session_id' => $sessionId,
                'session_ends_at' => $sessionEndsAt->toIso8601String(),
            ];
        });
    }

    private function isAvailable(Tariff $tariff, ClubMembership $membership, int $purchaseCount): bool
    {
        if ($tariff->quantity_limit_enabled && $tariff->quantity_limit !== null && $purchaseCount >= $tariff->quantity_limit) {
            return false;
        }

        $roles = $tariff->user_roles;
        if (is_array($roles) && $roles !== [] && ! in_array($membership->role, $roles, true)) {
            return false;
        }

        $periods = $tariff->validity_periods;
        if (! is_array($periods) || $periods === []) {
            return true;
        }

        $localNow = Carbon::now($this->currentClub->timezone());
        $dayNumbers = ['mon' => 0, 'tue' => 1, 'wed' => 2, 'thu' => 3, 'fri' => 4, 'sat' => 5, 'sun' => 6];
        $minuteOfWeek = ($localNow->dayOfWeekIso - 1) * 1440 + $localNow->hour * 60 + $localNow->minute;

        foreach ($periods as $period) {
            $startDay = $dayNumbers[$period['start_day'] ?? ''] ?? null;
            $endDay = $dayNumbers[$period['end_day'] ?? ''] ?? null;
            if ($startDay === null || $endDay === null) {
                continue;
            }

            $start = $startDay * 1440 + $this->minutesFromTime($period['start_time'] ?? '');
            $end = $endDay * 1440 + $this->minutesFromTime($period['end_time'] ?? '');
            if ($start <= $end ? $minuteOfWeek >= $start && $minuteOfWeek <= $end : $minuteOfWeek >= $start || $minuteOfWeek <= $end) {
                return true;
            }
        }

        return false;
    }

    private function purchaseCount(Tariff $tariff): int
    {
        return ActivityLog::query()
            ->where('event', 'tariff.purchased')
            ->where('subject_type', $tariff->getMorphClass())
            ->where('subject_id', $tariff->getKey())
            ->count();
    }

    /** @return array<string, mixed> */
    private function representation(Tariff $tariff, ClubMembership $membership): array
    {
        return [
            'id' => (string) $tariff->getKey(),
            'name' => $tariff->name,
            'description' => $tariff->description,
            'minutes' => $this->effectiveMinutes($tariff),
            'price' => self::formatCents($this->priceInCents($tariff, $membership)),
            'original_price' => (string) $tariff->price,
            'currency' => $tariff->currency,
            'zone_ids' => $tariff->zones->modelKeys(),
        ];
    }

    private function priceInCents(Tariff $tariff, ClubMembership $membership): int
    {
        $price = self::amountToCents((string) $tariff->price);
        $discountBasisPoints = $tariff->discount_enabled
            ? min(10000, max(0, (int) round((float) $membership->discount * 100)))
            : 0;

        return max(0, $price - intdiv($price * $discountBasisPoints + 5000, 10000));
    }

    private function effectiveMinutes(Tariff $tariff): int
    {
        return $tariff->usage_limit_enabled && $tariff->usage_limit_minutes !== null
            ? min($tariff->duration_minutes, $tariff->usage_limit_minutes)
            : $tariff->duration_minutes;
    }

    private static function amountToCents(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '00');

        return ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    private function minutesFromTime(string $time): int
    {
        if (! preg_match('/^(\d{2}):(\d{2})$/', $time, $matches)) {
            return 0;
        }

        return (int) $matches[1] * 60 + (int) $matches[2];
    }

    private static function formatCents(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}
