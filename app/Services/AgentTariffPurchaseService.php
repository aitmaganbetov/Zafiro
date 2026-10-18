<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ClubComputer;
use App\Models\ClubMembership;
use App\Models\ClubZone;
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
    public function __construct(private ActivityLogger $activity, private CurrentClub $currentClub, private ComputerReservations $reservations, private LoyaltyProgram $loyalty) {}

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

            $this->ensureComputerFor($computer, $user);

            if ($tariff->zones()->exists() && ! $tariff->zones()->whereKey($computer->club_zone_id)->exists()) {
                throw ValidationException::withMessages(['tariff' => __('Тариф недоступен для этого компьютера.')]);
            }

            $price = $this->priceInCents($tariff, $membership);

            return $this->sell($user, $computer, $price, $tariff->payment_method, $this->effectiveMinutes($tariff), function (array $sale) use ($user, $tariff, $price, $computer): void {
                $this->activity->record('tariff.purchased', __('Куплен тариф: :tariff', ['tariff' => $tariff->name]), $user->getKey(), $tariff, [
                    'amount' => self::formatCents($price),
                    'currency' => $tariff->currency,
                    'main_balance_amount' => self::formatCents($price - $sale['bonus_payment']),
                    'bonus_balance_amount' => self::formatCents($sale['bonus_payment']),
                    'duration_minutes' => $sale['minutes'],
                    'computer_id' => $computer->getKey(),
                    'session_id' => $sale['session_id'],
                    'session_ends_at' => $sale['session_ends_at']->toIso8601String(),
                ]);
            }, __('Тариф подключён.'));
        });
    }

    /**
     * Time by the minute at the hourly price of the PC's zone: minutes × hour price / 60, rounded
     * to the cent; paid from the main balance, the rest from bonuses (like a tariff "main or bonus").
     *
     * @return array<string, mixed>
     */
    public function purchaseMinutes(User $user, int $computerId, int $minutes): array
    {
        return DB::transaction(function () use ($user, $computerId, $minutes): array {
            $computer = ClubComputer::query()->lockForUpdate()->findOrFail($computerId);
            $zone = ClubZone::query()->find($computer->club_zone_id);
            if ($zone?->price_per_hour === null) {
                throw ValidationException::withMessages(['minutes' => __('В этой зоне время поминутно не продаётся.')]);
            }

            $this->ensureComputerFor($computer, $user);
            $price = self::minutesPriceInCents((string) $zone->price_per_hour, $minutes);
            $currency = data_get($this->currentClub->settings(), 'finances.currency', 'KZT');

            return $this->sell($user, $computer, $price, 'main_or_bonus', $minutes, function (array $sale) use ($user, $zone, $price, $currency, $computer): void {
                $this->activity->record('time.purchased', __('Куплено :count мин. в зоне :zone', ['count' => $sale['minutes'], 'zone' => $zone->name]), $user->getKey(), $zone, [
                    'amount' => self::formatCents($price),
                    'currency' => $currency,
                    'price_per_hour' => (string) $zone->price_per_hour,
                    'main_balance_amount' => self::formatCents($price - $sale['bonus_payment']),
                    'bonus_balance_amount' => self::formatCents($sale['bonus_payment']),
                    'duration_minutes' => $sale['minutes'],
                    'computer_id' => $computer->getKey(),
                    'session_id' => $sale['session_id'],
                    'session_ends_at' => $sale['session_ends_at']->toIso8601String(),
                ]);
            }, __('Время добавлено.'));
        });
    }

    /** Price of the given minutes at an hourly price, in cents (rounded half up). */
    public static function minutesPriceInCents(string $pricePerHour, int $minutes): int
    {
        return intdiv(self::amountToCents($pricePerHour) * $minutes + 30, 60);
    }

    /** The PC can take time for this customer: not reserved, no session of someone else. */
    private function ensureComputerFor(ClubComputer $computer, User $user): void
    {
        if ($computer->is_reserved) {
            throw ValidationException::withMessages(['computer_id' => __('Забронированный компьютер нельзя использовать для сессии.')]);
        }

        if ($computer->hasPaidSession() && $computer->current_session_user_id !== $user->getKey()) {
            throw ValidationException::withMessages(['computer_id' => __('На компьютере уже идёт сессия другого пользователя.')]);
        }
    }

    /**
     * Charges the balance and starts or extends the customer's session on the locked PC.
     *
     * @param  callable(array{bonus_payment: int, minutes: int, session_id: string, session_ends_at: Carbon}): void  $log
     * @return array<string, mixed>
     */
    private function sell(User $user, ClubComputer $computer, int $price, string $paymentMethod, int $minutes, callable $log, string $message): array
    {
        $balance = MemberBalance::query()->where('user_id', $user->getKey())->lockForUpdate()->firstOrFail();
        $mainBalance = self::amountToCents((string) $balance->balance);
        $bonusBalance = self::amountToCents((string) $balance->bonus_balance);
        $bonusPayment = 0;

        if ($paymentMethod === 'main_balance_only') {
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

        $hasActiveSession = $computer->hasPaidSession();
        $sessionId = $hasActiveSession ? $computer->current_session_id : (string) Str::uuid();
        $sessionEndsAt = ($hasActiveSession ? $computer->session_ends_at->copy() : now())->addMinutes($minutes);

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
        $this->loyalty->addSpending($user->getKey(), $price - $bonusPayment);
        $computer->update([
            'current_session_id' => $sessionId,
            'current_session_user_id' => $user->getKey(),
            'session_guest' => false,
            'session_ends_at' => $sessionEndsAt,
        ]);
        $log(['bonus_payment' => $bonusPayment, 'minutes' => $minutes, 'session_id' => $sessionId, 'session_ends_at' => $sessionEndsAt]);

        return [
            'success' => true,
            'message' => $message,
            'new_balance' => self::formatCents($mainBalance),
            'new_bonus' => self::formatCents($bonusBalance),
            'minutes_added' => $minutes,
            'session_id' => $sessionId,
            'session_ends_at' => $sessionEndsAt->toIso8601String(),
        ];
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
            ? min(10000, max(0, (int) round($this->loyalty->discountFor($membership) * 100)))
            : 0;

        return max(0, $price - intdiv($price * $discountBasisPoints + 5000, 10000));
    }

    public function effectiveMinutes(Tariff $tariff): int
    {
        return $tariff->usage_limit_enabled && $tariff->usage_limit_minutes !== null
            ? min($tariff->duration_minutes, $tariff->usage_limit_minutes)
            : $tariff->duration_minutes;
    }

    public static function amountToCents(string $amount): int
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

    public static function formatCents(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}
