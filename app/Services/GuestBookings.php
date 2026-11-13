<?php

namespace App\Services;

use App\Models\ClubComputer;
use App\Models\ClubZone;
use App\Models\ComputerReservation;
use App\Models\MemberBalance;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
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

    /** PCs a guest may book together. */
    public const MAX_COMPUTERS = 10;

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
     * The club's PCs by zone, for the guest to pick one first (whether it is free depends on the
     * time, see options()).
     *
     * @return list<array<string, mixed>>
     */
    public function computers(User $user): array
    {
        $zones = ClubZone::query()->orderBy('sort_order')->orderBy('id')->get(['id', 'name', 'price_per_hour']);
        $computers = ClubComputer::query()
            ->whereIn('club_zone_id', $zones->modelKeys())
            ->where('is_reserved', false)
            ->orderBy('number')
            ->get(['id', 'club_zone_id', 'number', 'display_name', 'device_name'])
            ->groupBy('club_zone_id');

        return $zones->filter(fn (ClubZone $zone): bool => isset($computers[$zone->id]))
            ->map(fn (ClubZone $zone): array => [
                'id' => $zone->id,
                'name' => $zone->name,
                'price_per_hour' => $zone->price_per_hour === null ? null : $this->purchases->hourlyPrice($user, $zone),
                'computers' => $computers[$zone->id]->map(fn (ClubComputer $computer): array => ['id' => $computer->id, 'name' => $computer->displayName()])->values()->all(),
            ])->values()->all();
    }

    /**
     * Tariffs the guest may book for the start, with their price and time; with PCs — only the
     * tariffs valid in the zones of all of them.
     *
     * @param  list<int>  $computerIds
     * @return list<array<string, mixed>>
     */
    public function tariffs(User $user, Carbon $startsAt, array $computerIds = []): array
    {
        $this->ensureStart($startsAt);
        $local = $startsAt->copy()->setTimezone($this->currentClub->localTimezone());
        $zoneIds = ClubComputer::query()->whereKey($computerIds)->distinct()->pluck('club_zone_id');

        return $this->purchases->bookableTariffs($user, $local)
            ->filter(fn (Tariff $tariff): bool => $tariff->zones->isEmpty() || $zoneIds->every(fn (int $zoneId): bool => $tariff->zones->contains('id', $zoneId)))
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

    /**
     * Books the PCs for one period and pays for all of them. They share one code: the guest's
     * friends enter it on the sign-in screen of those PCs (ComputerReservations::startByCode).
     *
     * @param  list<int>  $computerIds
     * @return Collection<int, ComputerReservation>
     */
    public function book(User $user, array $computerIds, Carbon $startsAt, ?int $minutes, ?int $tariffId): Collection
    {
        $computerIds = array_values(array_unique($computerIds));
        if ($computerIds === [] || count($computerIds) > self::MAX_COMPUTERS) {
            throw ValidationException::withMessages(['club_computer_ids' => __('Выберите от 1 до :count ПК.', ['count' => self::MAX_COMPUTERS])]);
        }

        return DB::transaction(function () use ($user, $computerIds, $startsAt, $minutes, $tariffId): Collection {
            // The tariff row serialises its purchase limit, as in the agent.
            if ($tariffId !== null) {
                Tariff::query()->lockForUpdate()->find($tariffId);
            }
            $plan = $this->plan($user, $startsAt, $minutes, $tariffId);
            $tariff = $plan['tariff'];
            if ($tariff !== null && ($left = $this->purchases->purchasesLeft($tariff)) !== null && $left < count($computerIds)) {
                throw ValidationException::withMessages(['tariff_id' => __('Тариф «:tariff» осталось купить на :count ПК.', ['tariff' => $tariff->name, 'count' => max(0, $left)])]);
            }
            // The PCs, then the balance (as a purchase): the balance row also serialises the guest's bookings.
            $computers = ClubComputer::query()->whereKey($computerIds)->orderBy('id')->lockForUpdate()->get();
            $zones = ClubZone::query()->whereKey($computers->pluck('club_zone_id')->unique())->get(['id', 'name', 'price_per_hour'])->keyBy('id');
            if ($computers->count() !== count($computerIds)) {
                throw ValidationException::withMessages(['club_computer_ids' => __('Этот ПК нельзя забронировать.')]);
            }
            $prices = [];
            foreach ($computers as $computer) {
                $zone = $zones->get($computer->club_zone_id);
                $fits = $tariff === null ? $zone?->price_per_hour !== null
                    : $zone !== null && ($tariff->zones->isEmpty() || $tariff->zones->contains('id', $zone->id));
                if (! $fits || $computer->is_reserved) {
                    throw ValidationException::withMessages(['club_computer_ids' => __('ПК :computer нельзя забронировать.', ['computer' => $computer->displayName()])]);
                }
                $prices[$computer->id] = $this->price($user, $zone, $plan);
            }
            $balance = MemberBalance::query()->where('user_id', $user->getKey())->lockForUpdate()->firstOrFail();
            // Bookings made together are one booking for the limit.
            $ahead = ComputerReservation::query()->where('user_id', $user->getKey())->pending()->where('ends_at', '>', now())
                ->get(['id', 'code'])->map(fn (ComputerReservation $reservation): string => $reservation->code ?? 'id'.$reservation->id)->unique()->count();
            if ($ahead >= self::MAX_PENDING) {
                throw ValidationException::withMessages(['starts_at' => trans_choice('У вас уже :count бронь в этом клубе — это предел.|У вас уже :count брони в этом клубе — это предел.|У вас уже :count броней в этом клубе — это предел.', $ahead)]);
            }

            $price = array_sum($prices);
            $mainBalance = AgentTariffPurchaseService::amountToCents((string) $balance->balance);
            $bonusBalance = AgentTariffPurchaseService::amountToCents((string) $balance->bonus_balance);
            $bonus = $this->purchases->bonusPayment($price, $tariff?->payment_method ?? 'main_or_bonus', null, $mainBalance, $bonusBalance,
                __('Недостаточно средств: бронь стоит :price.', ['price' => AgentTariffPurchaseService::formatCents($price)]));
            $main = $price - $bonus;

            $code = $this->newCode();
            $local = $startsAt->copy()->setTimezone($this->currentClub->localTimezone());
            $reservations = new Collection;
            $bonusLeft = $bonus;
            foreach ($computers->values() as $index => $computer) {
                // The bonus part is shared in proportion to the prices; the last PC takes the rest.
                $pcBonus = $index === $computers->count() - 1 ? $bonusLeft : ($price === 0 ? 0 : intdiv($bonus * $prices[$computer->id], $price));
                $bonusLeft -= $pcBonus;
                $reservations->push($this->reservations->create([
                    'club_computer_id' => $computer->getKey(),
                    'user_id' => $user->getKey(),
                    'customer_name' => $user->name,
                    'customer_phone' => $user->phone,
                    'note' => null,
                    'from_app' => true,
                    'paid_main_cents' => $prices[$computer->id] - $pcBonus,
                    'paid_bonus_cents' => $pcBonus,
                    'tariff_id' => $tariff?->getKey(),
                    'code' => $code,
                    'starts_at' => $startsAt->copy(),
                    'ends_at' => $startsAt->copy()->addMinutes($plan['minutes']),
                ], $user)->setRelation('tariff', $tariff));
            }

            $balance->update([
                'balance' => AgentTariffPurchaseService::formatCents($mainBalance - $main),
                'bonus_balance' => AgentTariffPurchaseService::formatCents($bonusBalance - $bonus),
            ]);
            $this->loyalty->addSpending($user->getKey(), $main);
            $names = $computers->map->displayName()->implode(', ');
            $this->activity->record('reservation.paid', __('Оплачена бронь ПК :computer на :from', [
                'computer' => $names,
                'from' => $local->format('d.m.Y H:i'),
            ]), $user->getKey(), $reservations->first(), [
                'amount' => AgentTariffPurchaseService::formatCents($price),
                'currency' => data_get($this->currentClub->settings(), 'finances.currency', 'KZT'),
                'main_balance_amount' => AgentTariffPurchaseService::formatCents($main),
                'bonus_balance_amount' => AgentTariffPurchaseService::formatCents($bonus),
                'computer' => $names,
                'zone' => $zones->pluck('name')->implode(', '),
                'tariff' => $tariff?->name,
                'starts_at' => $local->format('d.m.Y H:i'),
                'duration_minutes' => $plan['minutes'],
            ]);

            return $reservations;
        });
    }

    /** A code no booking ahead in the club has. */
    private function newCode(): string
    {
        do {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (ComputerReservation::query()->where('code', $code)->where('ends_at', '>', now()->subDay())->exists());

        return $code;
    }

    /** @param  array{minutes: int, tariff: ?Tariff}  $plan */
    private function price(User $user, ClubZone $zone, array $plan): int
    {
        return $plan['tariff'] !== null
            ? $this->purchases->tariffPrice($user, $plan['tariff'])
            : AgentTariffPurchaseService::minutesPriceInCents($this->purchases->hourlyPrice($user, $zone), $plan['minutes']);
    }

    /**
     * The guest cancels their booking (all its PCs) before it starts, when the club allows it:
     * the money comes back.
     */
    public function cancel(User $user, int $reservationId): void
    {
        $reservation = ComputerReservation::query()->where('user_id', $user->getKey())->findOrFail($reservationId);
        if (! $this->reservations->guestMayCancel()) {
            throw ValidationException::withMessages(['reservation' => __('Клуб не разрешает отменять бронь в приложении.')]);
        }
        $group = $this->group($reservation);
        if ($group->contains(fn (ComputerReservation $item): bool => $item->state() !== 'pending')) {
            throw ValidationException::withMessages(['reservation' => __('Бронь уже началась или закрыта — отменить её нельзя.')]);
        }

        DB::transaction(function () use ($group, $user): void {
            foreach ($group as $item) {
                $this->reservations->cancel($item, $user);
            }
        });
    }

    /**
     * The guest's bookings as the app shows them: PCs booked together are one booking.
     *
     * @param  Collection<int, ComputerReservation>  $reservations
     * @return list<array<string, mixed>>
     */
    public function presentAll(Collection $reservations): array
    {
        return $reservations->groupBy(fn (ComputerReservation $reservation): string => $reservation->code ?? 'id'.$reservation->id)
            ->map(fn (Collection $group): array => $this->present($group))
            ->values()->all();
    }

    /**
     * @param  Collection<int, ComputerReservation>  $group  PCs booked together (or one)
     * @return array<string, mixed>
     */
    public function present(Collection $group): array
    {
        $first = $group->first();
        $states = $group->map->state()->unique();
        // Together: still ahead while any PC is; otherwise as the first one.
        $state = $states->contains('active') ? 'active' : ($states->contains('pending') ? 'pending' : $first->state());
        $computers = $group->map(fn (ComputerReservation $reservation): ?string => $reservation->computer?->displayName())->filter()->values();

        return [
            'id' => $first->id,
            'code' => $first->code,
            'computer' => $computers->implode(', '),
            'computers' => $computers->all(),
            'zone' => $group->map(fn (ComputerReservation $reservation): ?string => $reservation->computer?->zone?->name)->filter()->unique()->implode(', ') ?: null,
            'tariff' => $first->tariff?->name,
            'starts_at' => $first->starts_at->utc()->toIso8601String(),
            'ends_at' => $first->ends_at->utc()->toIso8601String(),
            'state' => $state,
            'paid' => AgentTariffPurchaseService::formatCents($group->sum(fn (ComputerReservation $reservation): int => $reservation->paid_main_cents + $reservation->paid_bonus_cents)),
            'paid_bonus' => AgentTariffPurchaseService::formatCents($group->sum('paid_bonus_cents')),
            'can_cancel' => $states->all() === ['pending'] && $this->reservations->guestMayCancel(),
        ];
    }

    /** @return Collection<int, ComputerReservation> the bookings made together with this one */
    private function group(ComputerReservation $reservation): Collection
    {
        return $reservation->code === null ? new Collection([$reservation])
            : ComputerReservation::query()->where('user_id', $reservation->user_id)->where('code', $reservation->code)->orderBy('id')->get();
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
