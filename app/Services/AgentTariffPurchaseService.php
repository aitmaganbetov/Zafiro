<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ClubComputer;
use App\Models\ClubMembership;
use App\Models\ClubZone;
use App\Models\MemberBalance;
use App\Models\SessionPurchase;
use App\Models\Tariff;
use App\Models\TimePackage;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AgentTariffPurchaseService
{
    /**
     * The customer's choice of balance in the agent: all from the main one, or bonuses — the whole
     * price, or (mixed payment in the club) up to the club's percent and the rest from the main one.
     */
    public const BALANCES = ['main', 'bonus'];

    public function __construct(private ActivityLogger $activity, private CurrentClub $currentClub, private ComputerReservations $reservations, private LoyaltyProgram $loyalty, private PaymentRules $rules) {}

    /**
     * @param  ?int  $zoneId  the zone of the customer's PC: only its tariffs (and tariffs of no zone); null — all
     * @return Collection<int, array<string, mixed>>
     */
    public function availableFor(User $user, ?int $zoneId = null): Collection
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
            ->filter(fn (Tariff $tariff): bool => $zoneId === null || $tariff->zones->isEmpty() || $tariff->zones->contains('id', $zoneId))
            ->filter(fn (Tariff $tariff): bool => $this->isAvailable($tariff, $membership, (int) $purchases->get($tariff->getKey(), 0)))
            ->map(fn (Tariff $tariff): array => $this->representation($tariff, $membership))
            ->values();
    }

    /**
     * @param  ?string  $balance  main | bonus; null — agents before 0.0.57: the main balance first, then bonuses
     * @return array<string, mixed>
     */
    public function purchase(User $user, Tariff $tariff, int $computerId, ?string $balance = null): array
    {
        return DB::transaction(function () use ($user, $tariff, $computerId, $balance): array {
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
            // A usage limit: the tariff burns that long after it is bought; unused time is kept till then.
            $part = [
                'kind' => SessionPurchase::KIND_TARIFF,
                'tariff_id' => $tariff->getKey(),
                'name' => $tariff->name,
                'expires_at' => $tariff->usage_limit_enabled && $tariff->usage_limit_minutes !== null ? now()->addMinutes($tariff->usage_limit_minutes) : null,
            ];

            return $this->sell($user, $computer, $price, $tariff->payment_method, $balance, $this->effectiveMinutes($tariff), $part, function (array $sale) use ($user, $tariff, $price, $computer): void {
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
     * Time by the minute at the hourly price of the PC's zone (with the customer's discount when the
     * club applies it to hourly time): minutes × hour price / 60, rounded to the cent. Unused minutes
     * of a session ended early go back to the balances they were paid from.
     *
     * @return array<string, mixed>
     */
    public function purchaseMinutes(User $user, int $computerId, int $minutes, ?string $balance = null): array
    {
        return DB::transaction(function () use ($user, $computerId, $minutes, $balance): array {
            $computer = ClubComputer::query()->lockForUpdate()->findOrFail($computerId);
            $zone = ClubZone::query()->find($computer->club_zone_id);
            if ($zone?->price_per_hour === null) {
                throw ValidationException::withMessages(['minutes' => __('В этой зоне время поминутно не продаётся.')]);
            }

            $this->ensureComputerFor($computer, $user);
            $price = self::minutesPriceInCents($this->hourlyPrice($user, $zone), $minutes);
            $currency = data_get($this->currentClub->settings(), 'finances.currency', 'KZT');
            $part = ['kind' => SessionPurchase::KIND_MINUTES, 'tariff_id' => null, 'name' => $zone->name, 'expires_at' => null];

            return $this->sell($user, $computer, $price, 'main_or_bonus', $balance, $minutes, $part, function (array $sale) use ($user, $zone, $price, $currency, $computer): void {
                $this->activity->record('time.purchased', __('Куплено :count мин. в зоне :zone', ['count' => $sale['minutes'], 'zone' => $zone->name]), $user->getKey(), $zone, [
                    'amount' => self::formatCents($price),
                    'currency' => $currency,
                    'price_per_hour' => $this->hourlyPrice($user, $zone),
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

    /** The zone's hourly price for this customer: with their discount when the club applies it to hourly time. */
    public function hourlyPrice(User $user, ClubZone $zone): string
    {
        $price = self::amountToCents((string) $zone->price_per_hour);
        $membership = $user->currentMembership()->first();
        if ($membership === null || ! $this->rules->discountOnHourly()) {
            return self::formatCents($price);
        }

        return self::formatCents(self::discounted($price, $this->loyalty->discountFor($membership)));
    }

    /** @return list<array{id: string, name: string, minutes: int, expires_at: string}> the customer's kept tariff time */
    public function packagesFor(User $user): array
    {
        return TimePackage::query()->where('user_id', $user->getKey())->where('expires_at', '>', now())
            ->orderBy('expires_at')->get()
            ->map(fn (TimePackage $package): array => [
                'id' => (string) $package->getKey(),
                'name' => $package->name,
                'minutes' => $package->minutes_left,
                'expires_at' => $package->expires_at->utc()->toIso8601String(),
            ])->all();
    }

    /**
     * Starts the customer's kept tariff time on this PC (no charge): as much as is left, but not
     * past its expiry; within the tariff's zones and periods while the tariff exists.
     *
     * @return array<string, mixed>
     */
    public function startPackage(User $user, TimePackage $package, int $computerId): array
    {
        return DB::transaction(function () use ($user, $package, $computerId): array {
            $package = TimePackage::query()->where('user_id', $user->getKey())->lockForUpdate()->findOrFail($package->getKey());
            $computer = ClubComputer::query()->lockForUpdate()->findOrFail($computerId);
            $minutes = min($package->minutes_left, intdiv(max(0, $package->expires_at->getTimestamp() - now()->getTimestamp()), 60));
            if ($minutes <= 0) {
                $package->delete();
                throw ValidationException::withMessages(['package' => __('Срок абонемента истёк.')]);
            }

            $this->ensureComputerFor($computer, $user);
            $tariff = $package->tariff;
            if ($tariff !== null && $tariff->zones()->exists() && ! $tariff->zones()->whereKey($computer->club_zone_id)->exists()) {
                throw ValidationException::withMessages(['package' => __('Абонемент не действует на этом компьютере.')]);
            }
            if ($tariff !== null && ! $tariff->inValidityPeriod()) {
                throw ValidationException::withMessages(['package' => __('Абонемент сейчас не действует: проверьте дни и время тарифа.')]);
            }

            $part = ['kind' => SessionPurchase::KIND_TARIFF, 'tariff_id' => $package->tariff_id, 'name' => $package->name, 'expires_at' => $package->expires_at];
            $result = $this->sell($user, $computer, 0, 'main_balance_only', 'main', $minutes, $part, function (array $sale) use ($user, $package, $computer): void {
                $this->activity->record('time.package_started', __('Запущен абонемент :name: :count мин.', ['name' => $package->name, 'count' => $sale['minutes']]), $user->getKey(), $computer, [
                    'minutes' => $sale['minutes'],
                    'session_id' => $sale['session_id'],
                    'session_ends_at' => $sale['session_ends_at']->toIso8601String(),
                ]);
            }, __('Абонемент запущен.'));
            $package->delete();

            return $result;
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
     * The price is paid by the customer's choice: "main" — all from the main balance; "bonus" —
     * bonuses up to the club's percent of the price, the rest from the main balance when the club
     * allows mixed payment (otherwise bonuses pay the whole price); none (older agents) — the main
     * balance first, then bonuses the same way. A tariff "main balance only" takes no bonuses.
     * The part is written to the session (SessionSettlement).
     *
     * @param  array{kind: string, tariff_id: ?int, name: string, expires_at: ?Carbon}  $part
     * @param  callable(array{bonus_payment: int, minutes: int, session_id: string, session_ends_at: Carbon}): void  $log
     * @return array<string, mixed>
     */
    private function sell(User $user, ClubComputer $computer, int $price, string $paymentMethod, ?string $choice, int $minutes, array $part, callable $log, string $message): array
    {
        $balance = MemberBalance::query()->where('user_id', $user->getKey())->lockForUpdate()->firstOrFail();
        $mainBalance = self::amountToCents((string) $balance->balance);
        $bonusBalance = self::amountToCents((string) $balance->bonus_balance);
        $bonusLimit = $paymentMethod === 'main_balance_only' ? 0 : $this->rules->bonusLimitCents($price);
        $mixed = $this->rules->mixedPaymentAllowed();

        if ($choice === 'bonus') {
            if ($bonusLimit === 0 && $price > 0) {
                throw ValidationException::withMessages(['balance' => $paymentMethod === 'main_balance_only'
                    ? __('Этот тариф оплачивается только с основного баланса.')
                    : __('В клубе бонусами нельзя оплачивать время.')]);
            }
            $bonusPayment = min($bonusBalance, $bonusLimit);
            if (! $mixed && $bonusPayment < $price) {
                throw ValidationException::withMessages(['balance' => $bonusLimit < $price
                    ? __('Бонусами можно оплатить только :percent% цены, а смешанная оплата в клубе выключена. Оплатите с основного баланса.', ['percent' => $this->rules->bonusMaxPercent()])
                    : __('Бонусов не хватает на всю цену. Оплатите с основного баланса.')]);
            }
        } elseif ($choice === 'main') {
            $bonusPayment = 0;
        } elseif ($mixed) {
            $bonusPayment = min($bonusLimit, max(0, $price - $mainBalance));
        } else {
            // One balance: the main one, or bonuses for the whole price when the main one is short.
            $bonusPayment = $mainBalance < $price && $bonusLimit >= $price && $bonusBalance >= $price ? $price : 0;
        }
        $mainPayment = $price - $bonusPayment;
        if ($mainBalance < $mainPayment || $bonusBalance < $bonusPayment) {
            throw ValidationException::withMessages(['balance' => __('Недостаточно средств для покупки тарифа.')]);
        }
        $mainBalance -= $mainPayment;
        $bonusBalance -= $bonusPayment;

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
        $this->loyalty->addSpending($user->getKey(), $mainPayment);
        SessionPurchase::query()->create($part + [
            'session_id' => $sessionId,
            'user_id' => $user->getKey(),
            'minutes' => $minutes,
            'main_cents' => $mainPayment,
            'bonus_cents' => $bonusPayment,
        ]);
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
        if (! $tariff->onSale()) {
            return false;
        }

        if ($tariff->quantity_limit_enabled && $tariff->quantity_limit !== null && $purchaseCount >= $tariff->quantity_limit) {
            return false;
        }

        $roles = $tariff->user_roles;
        if (is_array($roles) && $roles !== [] && ! in_array($membership->role, $roles, true)) {
            return false;
        }

        return $tariff->inValidityPeriod();
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
            // The agent offers "pay with bonuses" only when bonuses may pay for it (see PaymentRules).
            'bonus_allowed' => $this->rules->bonusPercentOffered($tariff->payment_method === 'main_balance_only') > 0,
            'bonus_max_percent' => $this->rules->bonusPercentOffered($tariff->payment_method === 'main_balance_only'),
            'mixed_payment' => $this->rules->mixedPaymentAllowed(),
            // Unused time is kept until it burns this long after the purchase.
            'usage_limit_minutes' => $tariff->usage_limit_enabled ? $tariff->usage_limit_minutes : null,
        ];
    }

    private function priceInCents(Tariff $tariff, ClubMembership $membership): int
    {
        $price = self::amountToCents((string) $tariff->price);

        return $tariff->discount_enabled && $this->rules->discountOnTariffs()
            ? self::discounted($price, $this->loyalty->discountFor($membership))
            : $price;
    }

    /** A price in cents less a percent, rounded half up. */
    private static function discounted(int $price, float $percent): int
    {
        $basisPoints = min(10000, max(0, (int) round($percent * 100)));

        return max(0, $price - intdiv($price * $basisPoints + 5000, 10000));
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

    public static function formatCents(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}
