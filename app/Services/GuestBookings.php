<?php

namespace App\Services;

use App\Models\ClubComputer;
use App\Models\ClubZone;
use App\Models\ComputerReservation;
use App\Models\MemberBalance;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Booking a PC in the guest app: by the hour (a duration at the zone's hourly price) or a tariff;
 * the guest picks a zone, a PC free for the period and pays from the balances (with their discount). See ComputerReservations for
 * how the paid time starts and when the money comes back.
 */
class GuestBookings
{
    /** Bookings a guest may have ahead at once in a club. */
    public const MAX_PENDING = 2;

    public const MAX_DAYS_AHEAD = 7;

    public const MIN_MINUTES = 30;

    public const MAX_MINUTES = 720;

    public function __construct(
        private ComputerReservations $reservations,
        private AgentTariffPurchaseService $purchases,
        private ActivityLogger $activity,
        private LoyaltyProgram $loyalty,
        private CurrentClub $currentClub,
    ) {}

    public function ensureEnabled(): void
    {
        if (! $this->reservations->appBookingEnabled()) {
            throw new HttpException(403, __('Бронь из приложения в этом клубе выключена.'));
        }
    }

    /**
     * What is booked: by the hour for $minutes, or a tariff (its time from the start, cut at the
     * end of its validity period).
     *
     * @return array{minutes: int, tariff: ?Tariff}
     */
    public function plan(User $user, Carbon $startsAt, ?int $minutes, ?int $tariffId): array
    {
        if ($tariffId === null) {
            $this->ensurePeriod($startsAt, (int) $minutes);

            return ['minutes' => (int) $minutes, 'tariff' => null];
        }

        $this->ensureStart($startsAt);
        $local = $startsAt->copy()->setTimezone($this->currentClub->localTimezone());
        $tariff = $this->purchases->bookableTariffs($user, $local)->firstWhere('id', $tariffId);
        $minutes = $tariff === null ? 0 : $this->purchases->bookedMinutes($tariff, $local);
        if ($minutes <= 0) {
            throw ValidationException::withMessages(['tariff_id' => __('Этот тариф нельзя забронировать на это время.')]);
        }

        return ['minutes' => $minutes, 'tariff' => $tariff];
    }

    /**
     * Tariffs the guest may book for the start, with their price and time.
     *
     * @return list<array<string, mixed>>
     */
    public function tariffs(User $user, Carbon $startsAt): array
    {
        $this->ensureStart($startsAt);
        $local = $startsAt->copy()->setTimezone($this->currentClub->localTimezone());

        return $this->purchases->bookableTariffs($user, $local)
            ->map(fn (Tariff $tariff): array => [
                'id' => $tariff->id,
                'name' => $tariff->name,
                'description' => $tariff->description,
                'minutes' => $this->purchases->bookedMinutes($tariff, $local),
                'price' => AgentTariffPurchaseService::formatCents($this->purchases->tariffPrice($user, $tariff)),
                'zone_ids' => $tariff->zones->modelKeys(),
            ])
            ->filter(fn (array $tariff): bool => $tariff['minutes'] > 0)
            ->values()->all();
    }

    /**
     * Zones of the booking (with an hourly price, or the tariff's zones), its price there and the
     * zone's PCs, each free or not for the period.
     *
     * @param  array{minutes: int, tariff: ?Tariff}  $plan
     * @return list<array<string, mixed>>
     */
    public function options(User $user, Carbon $startsAt, array $plan): array
    {
        $gap = $this->reservations->gapMinutes();
        $from = $startsAt->copy()->subMinutes($gap);
        $to = $startsAt->copy()->addMinutes($plan['minutes'] + $gap);
        $tariff = $plan['tariff'];

        $zones = ClubZone::query()
            ->when($tariff === null, fn ($query) => $query->whereNotNull('price_per_hour'))
            ->when($tariff !== null && $tariff->zones->isNotEmpty(), fn ($query) => $query->whereKey($tariff->zones->modelKeys()))
            ->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'name', 'price_per_hour']);
        $computers = ClubComputer::query()
            ->whereIn('club_zone_id', $zones->modelKeys())
            ->orderBy('number')
            ->get(['id', 'club_zone_id', 'number', 'display_name', 'device_name', 'is_reserved', 'current_session_id', 'current_session_user_id', 'session_guest', 'session_ends_at']);
        $booked = ComputerReservation::query()
            ->whereIn('club_computer_id', $computers->modelKeys())
            ->overlapping($from, $to)
            ->distinct()
            ->pluck('club_computer_id')
            ->flip();
        $computers = $computers->groupBy('club_zone_id');

        return $zones->map(function (ClubZone $zone) use ($user, $plan, $computers, $booked, $from): array {
            $hourly = $zone->price_per_hour === null ? null : $this->purchases->hourlyPrice($user, $zone);

            return [
                'id' => $zone->id,
                'name' => $zone->name,
                'price_per_hour' => $hourly,
                'price' => AgentTariffPurchaseService::formatCents($this->price($user, $zone, $plan)),
                'computers' => ($computers[$zone->id] ?? collect())->map(fn (ClubComputer $computer): array => [
                    'id' => $computer->id,
                    'name' => $computer->displayName(),
                    'available' => ! $computer->is_reserved && ! isset($booked[$computer->id])
                        && ! ($computer->hasPaidSession() && $computer->session_ends_at->gt($from)),
                ])->values()->all(),
            ];
        })->values()->all();
    }

    public function book(User $user, int $computerId, Carbon $startsAt, ?int $minutes, ?int $tariffId): ComputerReservation
    {
        return DB::transaction(function () use ($user, $computerId, $startsAt, $minutes, $tariffId): ComputerReservation {
            // The tariff row serialises its purchase limit, as in the agent.
            if ($tariffId !== null) {
                Tariff::query()->lockForUpdate()->find($tariffId);
            }
            $plan = $this->plan($user, $startsAt, $minutes, $tariffId);
            $tariff = $plan['tariff'];
            // The PC, then the balance (as a purchase): the balance row also serialises the guest's bookings.
            $computer = ClubComputer::query()->lockForUpdate()->find($computerId);
            $zone = $computer === null ? null : ClubZone::query()->find($computer->club_zone_id, ['id', 'name', 'price_per_hour']);
            $fits = $tariff === null ? $zone?->price_per_hour !== null
                : $zone !== null && ($tariff->zones->isEmpty() || $tariff->zones->contains('id', $zone->id));
            if (! $fits || $computer->is_reserved) {
                throw ValidationException::withMessages(['club_computer_id' => __('Этот ПК нельзя забронировать.')]);
            }
            $balance = MemberBalance::query()->where('user_id', $user->getKey())->lockForUpdate()->firstOrFail();
            $ahead = ComputerReservation::query()->where('user_id', $user->getKey())->pending()->where('ends_at', '>', now())->count();
            if ($ahead >= self::MAX_PENDING) {
                throw ValidationException::withMessages(['starts_at' => trans_choice('У вас уже :count бронь в этом клубе — это предел.|У вас уже :count брони в этом клубе — это предел.|У вас уже :count броней в этом клубе — это предел.', $ahead)]);
            }

            $price = $this->price($user, $zone, $plan);
            $mainBalance = AgentTariffPurchaseService::amountToCents((string) $balance->balance);
            $bonusBalance = AgentTariffPurchaseService::amountToCents((string) $balance->bonus_balance);
            $bonus = $this->purchases->bonusPayment($price, $tariff?->payment_method ?? 'main_or_bonus', null, $mainBalance, $bonusBalance,
                __('Недостаточно средств: бронь стоит :price.', ['price' => AgentTariffPurchaseService::formatCents($price)]));
            $main = $price - $bonus;

            $reservation = $this->reservations->create([
                'club_computer_id' => $computer->getKey(),
                'user_id' => $user->getKey(),
                'customer_name' => $user->name,
                'customer_phone' => $user->phone,
                'note' => null,
                'from_app' => true,
                'paid_main_cents' => $main,
                'paid_bonus_cents' => $bonus,
                'tariff_id' => $tariff?->getKey(),
                'starts_at' => $startsAt->copy(),
                'ends_at' => $startsAt->copy()->addMinutes($plan['minutes']),
            ], $user);

            $balance->update([
                'balance' => AgentTariffPurchaseService::formatCents($mainBalance - $main),
                'bonus_balance' => AgentTariffPurchaseService::formatCents($bonusBalance - $bonus),
            ]);
            $this->loyalty->addSpending($user->getKey(), $main);
            $this->activity->record('reservation.paid', __('Оплачена бронь ПК :computer на :from', [
                'computer' => $computer->displayName(),
                'from' => $reservation->starts_at->format('d.m.Y H:i'),
            ]), $user->getKey(), $reservation, [
                'amount' => AgentTariffPurchaseService::formatCents($price),
                'currency' => data_get($this->currentClub->settings(), 'finances.currency', 'KZT'),
                'main_balance_amount' => AgentTariffPurchaseService::formatCents($main),
                'bonus_balance_amount' => AgentTariffPurchaseService::formatCents($bonus),
                'computer' => $computer->displayName(),
                'zone' => $zone->name,
                'tariff' => $tariff?->name,
                'starts_at' => $reservation->starts_at->format('d.m.Y H:i'),
                'duration_minutes' => $plan['minutes'],
            ]);

            return $reservation->setRelation('tariff', $tariff);
        });
    }

    /** @param  array{minutes: int, tariff: ?Tariff}  $plan */
    private function price(User $user, ClubZone $zone, array $plan): int
    {
        return $plan['tariff'] !== null
            ? $this->purchases->tariffPrice($user, $plan['tariff'])
            : AgentTariffPurchaseService::minutesPriceInCents($this->purchases->hourlyPrice($user, $zone), $plan['minutes']);
    }

    /** The guest cancels their booking before it starts, when the club allows it: the money comes back. */
    public function cancel(User $user, int $reservationId): void
    {
        $reservation = ComputerReservation::query()->where('user_id', $user->getKey())->findOrFail($reservationId);
        if (! $this->reservations->guestMayCancel()) {
            throw ValidationException::withMessages(['reservation' => __('Клуб не разрешает отменять бронь в приложении.')]);
        }
        if ($reservation->state() !== 'pending') {
            throw ValidationException::withMessages(['reservation' => __('Бронь уже началась или закрыта — отменить её нельзя.')]);
        }

        $this->reservations->cancel($reservation, $user);
    }

    /** @return array<string, mixed> a booking as the app shows it */
    public function present(ComputerReservation $reservation): array
    {
        $state = $reservation->state();

        return [
            'id' => $reservation->id,
            'computer' => $reservation->computer?->displayName(),
            'zone' => $reservation->computer?->zone?->name,
            'tariff' => $reservation->tariff?->name,
            'starts_at' => $reservation->starts_at->utc()->toIso8601String(),
            'ends_at' => $reservation->ends_at->utc()->toIso8601String(),
            'state' => $state,
            'paid' => AgentTariffPurchaseService::formatCents($reservation->paid_main_cents + $reservation->paid_bonus_cents),
            'paid_bonus' => AgentTariffPurchaseService::formatCents($reservation->paid_bonus_cents),
            'can_cancel' => $state === 'pending' && $this->reservations->guestMayCancel(),
        ];
    }

    private function ensurePeriod(Carbon $startsAt, int $minutes): void
    {
        $this->ensureStart($startsAt);
        if ($minutes < self::MIN_MINUTES || $minutes > self::MAX_MINUTES) {
            throw ValidationException::withMessages(['duration_minutes' => __('Длительность брони — от :min до :max минут.', ['min' => self::MIN_MINUTES, 'max' => self::MAX_MINUTES])]);
        }
    }

    private function ensureStart(Carbon $startsAt): void
    {
        if ($startsAt->lt(now()->subMinute())) {
            throw ValidationException::withMessages(['starts_at' => __('Нельзя забронировать ПК на прошедшее время.')]);
        }
        if ($startsAt->gt(now()->addDays(self::MAX_DAYS_AHEAD))) {
            throw ValidationException::withMessages(['starts_at' => trans_choice('Бронь — не дальше чем на :count день вперёд.|Бронь — не дальше чем на :count дня вперёд.|Бронь — не дальше чем на :count дней вперёд.', self::MAX_DAYS_AHEAD)]);
        }
    }
}
