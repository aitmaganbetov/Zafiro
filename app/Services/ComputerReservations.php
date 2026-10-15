<?php

namespace App\Services;

use App\Models\ClubComputer;
use App\Models\ComputerReservation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PC bookings. The club's "interval between bookings" is kept free before and after each
 * booking: no other booking or paid session may run into it, and the PC shows as reserved
 * that long before the booking starts.
 */
class ComputerReservations
{
    public const DEFAULT_GAP_MINUTES = 30;

    public function __construct(private ActivityLogger $activity, private CurrentClub $currentClub) {}

    public function gapMinutes(): int
    {
        $value = $this->currentClub->settings()['club']['booking_interval'] ?? self::DEFAULT_GAP_MINUTES;

        return is_numeric($value) ? max(0, (int) round((float) $value)) : self::DEFAULT_GAP_MINUTES;
    }

    /**
     * @param  array{club_computer_id: int, user_id: ?int, customer_name: ?string, customer_phone: ?string, note: ?string, starts_at: Carbon, ends_at: Carbon}  $data
     */
    public function create(array $data, User $actor): ComputerReservation
    {
        return DB::transaction(function () use ($data, $actor): ComputerReservation {
            // The PC row serialises bookings and tariff purchases on it.
            $computer = ClubComputer::query()->lockForUpdate()->findOrFail($data['club_computer_id']);
            $gap = $this->gapMinutes();
            $startsAt = $data['starts_at'];
            $endsAt = $data['ends_at'];

            $conflict = ComputerReservation::query()
                ->where('club_computer_id', $computer->getKey())
                ->overlapping($startsAt->copy()->subMinutes($gap), $endsAt->copy()->addMinutes($gap))
                ->orderBy('starts_at')
                ->first();
            if ($conflict !== null) {
                throw ValidationException::withMessages(['starts_at' => __('ПК уже забронирован с :from до :to (между бронями нужно :count мин).', [
                    'from' => $conflict->starts_at->format('d.m H:i'),
                    'to' => $conflict->ends_at->format('H:i'),
                    'count' => $gap,
                ])]);
            }

            if ($computer->hasPaidSession() && $computer->session_ends_at->gt($startsAt->copy()->subMinutes($gap))) {
                throw ValidationException::withMessages(['starts_at' => __('На ПК идёт сессия до :time.', ['time' => $computer->session_ends_at->format('d.m H:i')])]);
            }

            $reservation = ComputerReservation::query()->create([
                ...$data,
                'created_by' => $actor->getKey(),
            ]);
            $reservation->setRelation('computer', $computer);
            $this->activity->record('reservation.created', __('ПК :computer забронирован на :from – :to', [
                'computer' => $computer->displayName(),
                'from' => $startsAt->format('d.m.Y H:i'),
                'to' => $endsAt->format('H:i'),
            ]), $actor->getKey(), $reservation, [
                'computer' => $computer->displayName(),
                'starts_at' => $startsAt->format('d.m.Y H:i'),
                'ends_at' => $endsAt->format('d.m.Y H:i'),
            ]);

            return $reservation;
        });
    }

    public function cancel(ComputerReservation $reservation, User $actor): void
    {
        $this->close($reservation, $actor, 'cancelled_at', 'reservation.cancelled', fn (array $names): string => __('Бронь ПК :computer на :from отменена', $names));
    }

    /** The customer came: the PC is no longer held, anyone signed in on it may buy time. */
    public function arrive(ComputerReservation $reservation, User $actor): void
    {
        $this->close($reservation, $actor, 'arrived_at', 'reservation.arrived', fn (array $names): string => __('Клиент пришёл по брони ПК :computer на :from', $names));
    }

    /**
     * A booking of someone else that a session on the PC from now until $until would run into
     * (including the interval between bookings). The booked customer may use the PC.
     */
    public function blocking(ClubComputer $computer, int $userId, Carbon $until): ?ComputerReservation
    {
        return ComputerReservation::query()
            ->where('club_computer_id', $computer->getKey())
            ->overlapping(now(), $until->copy()->addMinutes($this->gapMinutes()))
            ->where(fn ($query) => $query->whereNull('user_id')->orWhere('user_id', '!=', $userId))
            ->orderBy('starts_at')
            ->first();
    }

    /** The booked customer started a session on the PC: their booking is fulfilled. */
    public function markArrivedBy(ClubComputer $computer, int $userId, Carbon $until): void
    {
        ComputerReservation::query()
            ->where('club_computer_id', $computer->getKey())
            ->where('user_id', $userId)
            ->overlapping(now(), $until->copy()->addMinutes($this->gapMinutes()))
            ->update(['arrived_at' => now()]);
    }

    /**
     * PCs held by a booking now or within the interval before it starts.
     *
     * @return list<int>
     */
    public function heldComputerIds(): array
    {
        return ComputerReservation::query()
            ->overlapping(now(), now()->addMinutes($this->gapMinutes()))
            ->distinct()
            ->pluck('club_computer_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /** @param  callable(array{computer: string, from: string}): string  $description */
    private function close(ComputerReservation $reservation, User $actor, string $column, string $event, callable $description): void
    {
        DB::transaction(function () use ($reservation, $actor, $column, $event, $description): void {
            $reservation = ComputerReservation::query()->with('computer')->lockForUpdate()->findOrFail($reservation->getKey());
            if (! in_array($reservation->state(), ['pending', 'active'], true)) {
                throw ValidationException::withMessages(['reservation' => __('Эта бронь уже закрыта.')]);
            }

            $reservation->update([$column => now()]);
            $names = ['computer' => $reservation->computer?->displayName() ?? '—', 'from' => $reservation->starts_at->format('d.m.Y H:i')];
            $this->activity->record($event, $description($names), $actor->getKey(), $reservation, [
                'computer' => $names['computer'],
                'starts_at' => $names['from'],
            ]);
        });
    }
}
