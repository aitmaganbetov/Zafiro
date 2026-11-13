<?php

namespace App\Services;

use App\Models\ClubComputer;
use App\Models\ComputerReservation;
use App\Models\MemberBalance;
use App\Models\SessionPurchase;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * PC bookings. The club's "interval between bookings" is kept free before and after each
 * booking: no other booking or paid session may run into it, and the PC shows as reserved
 * that long before the booking starts.
 *
 * A booking from the guest app is paid in advance (GuestBookings). Its time starts on the PC when
 * the guest signs in there during the booking (from the interval before it) or staff mark the
 * arrival; a cancelled one gives the money back, a missed one does not.
 */
class ComputerReservations
{
    public const DEFAULT_GAP_MINUTES = 30;

    public function __construct(private ActivityLogger $activity, private CurrentClub $currentClub, private LoyaltyProgram $loyalty) {}

    /** Guests book and pay for PCs in the mobile app (club settings, off by default). */
    public function appBookingEnabled(): bool
    {
        return filter_var($this->currentClub->settings()['club']['app_booking_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /** A guest may cancel a paid booking before it starts and get the money back (on by default). */
    public function guestMayCancel(): bool
    {
        return filter_var($this->currentClub->settings()['club']['app_booking_cancel'] ?? true, FILTER_VALIDATE_BOOLEAN);
    }

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
                    'from' => $this->local($conflict->starts_at)->format('d.m H:i'),
                    'to' => $this->local($conflict->ends_at)->format('H:i'),
                    'count' => $gap,
                ])]);
            }

            if ($computer->hasPaidSession() && $computer->session_ends_at->gt($startsAt->copy()->subMinutes($gap))) {
                throw ValidationException::withMessages(['starts_at' => __('На ПК идёт сессия до :time.', ['time' => $this->local($computer->session_ends_at)->format('d.m H:i')])]);
            }

            $reservation = ComputerReservation::query()->create([
                ...$data,
                'created_by' => $actor->getKey(),
            ]);
            $reservation->setRelation('computer', $computer);
            $this->activity->record('reservation.created', __('ПК :computer забронирован на :from – :to', [
                'computer' => $computer->displayName(),
                'from' => $this->local($startsAt)->format('d.m.Y H:i'),
                'to' => $this->local($endsAt)->format('H:i'),
            ]), $actor->getKey(), $reservation, [
                'computer' => $computer->displayName(),
                'starts_at' => $this->local($startsAt)->format('d.m.Y H:i'),
                'ends_at' => $this->local($endsAt)->format('d.m.Y H:i'),
            ]);

            return $reservation;
        });
    }

    public function cancel(ComputerReservation $reservation, User $actor): void
    {
        $this->close($reservation, $actor, 'cancelled_at', 'reservation.cancelled', fn (array $names): string => __('Бронь ПК :computer на :from отменена', $names));
    }

    /**
     * The customer came: the PC is no longer held, anyone signed in on it may buy time. The time of
     * a paid booking starts on the PC now.
     */
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
            ->unpaid()
            ->overlapping(now(), $until->copy()->addMinutes($this->gapMinutes()))
            ->update(['arrived_at' => now()]);
    }

    /**
     * The guest is signed in on the PC (agent heartbeat): their paid booking that starts within the
     * interval before it starts its time on the PC. Most heartbeats stop at the cheap check.
     */
    public function startForSignedIn(ClubComputer $computer, int $userId): bool
    {
        if ($computer->hasPaidSession() && ($computer->session_guest || $computer->current_session_user_id !== $userId)) {
            return false;
        }
        $due = fn () => ComputerReservation::query()
            ->where('club_computer_id', $computer->getKey())
            ->where('user_id', $userId)
            ->pending()
            ->paid()
            ->where('starts_at', '<=', now()->addMinutes($this->gapMinutes()))
            ->where('ends_at', '>', now());
        if (! $due()->exists()) {
            return false;
        }

        return DB::transaction(function () use ($computer, $userId, $due): bool {
            $computer = ClubComputer::query()->lockForUpdate()->findOrFail($computer->getKey());
            $reservation = $due()->orderBy('starts_at')->lockForUpdate()->first();
            if ($reservation === null || ! $this->startPaidSession($reservation, $computer)) {
                return false;
            }
            $this->recordArrival($reservation, $computer, $userId);

            return true;
        });
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

    /**
     * The code of a booking from the guest app typed on the PC's sign-in screen: when this PC is
     * one of the booked ones and the booking is due (from the interval before it), its paid time
     * starts here as a session without an account.
     */
    public function startByCode(ClubComputer $computer, string $code): ClubComputer
    {
        return DB::transaction(function () use ($computer, $code): ClubComputer {
            $computer = ClubComputer::query()->lockForUpdate()->findOrFail($computer->getKey());
            $reservation = ComputerReservation::query()
                ->where('club_computer_id', $computer->getKey())
                ->where('code', $code)
                ->pending()
                ->where('ends_at', '>', now())
                ->orderBy('starts_at')
                ->lockForUpdate()
                ->first();
            if ($reservation === null) {
                $elsewhere = ComputerReservation::query()->where('code', $code)->pending()->where('ends_at', '>', now())->exists();
                throw ValidationException::withMessages(['code' => $elsewhere
                    ? __('Этот код брони не для этого ПК: сядьте за забронированный ПК.')
                    : __('Код брони не найден или бронь уже закончилась.')]);
            }
            $gap = $this->gapMinutes();
            if ($reservation->starts_at->gt(now()->addMinutes($gap))) {
                throw ValidationException::withMessages(['code' => __('Бронь начнётся в :time. Код заработает за :count мин до начала.', [
                    'time' => $this->local($reservation->starts_at)->format('d.m H:i'),
                    'count' => $gap,
                ])]);
            }
            if (! $this->startPaidSession($reservation, $computer, true)) {
                throw ValidationException::withMessages(['code' => __('На этом ПК уже идёт сессия.')]);
            }
            $names = ['computer' => $computer->displayName(), 'from' => $this->local($reservation->starts_at)->format('d.m.Y H:i')];
            $this->activity->record('reservation.arrived', __('ПК :computer открыт по коду брони на :from', $names), $reservation->user_id, $reservation, [
                'computer' => $names['computer'],
                'starts_at' => $names['from'],
                'by_code' => true,
            ]);

            return $computer;
        });
    }

    /** Times in texts are the club's local time: the API reads dates in UTC. */
    private function local(Carbon $time): Carbon
    {
        return $time->copy()->setTimezone($this->currentClub->localTimezone());
    }

    /** @param  callable(array{computer: string, from: string}): string  $description */
    private function close(ComputerReservation $reservation, User $actor, string $column, string $event, callable $description): void
    {
        DB::transaction(function () use ($reservation, $actor, $column, $event, $description): void {
            // The PC first, as bookings and purchases on it lock it.
            $computer = ClubComputer::query()->lockForUpdate()->find($reservation->club_computer_id);
            $reservation = ComputerReservation::query()->lockForUpdate()->findOrFail($reservation->getKey());
            $reservation->setRelation('computer', $computer);
            if (! in_array($reservation->state(), ['pending', 'active'], true)) {
                throw ValidationException::withMessages(['reservation' => __('Эта бронь уже закрыта.')]);
            }

            if ($column === 'arrived_at' && $reservation->isPaid()) {
                if ($computer === null || ! $this->startPaidSession($reservation, $computer)) {
                    throw ValidationException::withMessages(['reservation' => __('На ПК идёт сессия другого клиента: оплаченное время брони начнётся, когда ПК освободится.')]);
                }
            } else {
                $reservation->update([$column => now()]);
            }
            if ($column === 'cancelled_at' && $reservation->isPaid()) {
                $this->refund($reservation, $actor);
            }

            $names = ['computer' => $computer?->displayName() ?? '—', 'from' => $this->local($reservation->starts_at)->format('d.m.Y H:i')];
            $this->activity->record($event, $description($names), $actor->getKey(), $reservation, [
                'computer' => $names['computer'],
                'starts_at' => $names['from'],
            ]);
        });
    }

    /**
     * Starts the paid time of the booking on the locked PC, or adds it to the guest's own session
     * there. The time is the booked duration but ends with the booking at the latest (late —
     * the missed part is not refunded). Paid by the hour, the amount goes with it as minutes, so a
     * session ended early gives back the unused part (SessionSettlement); a tariff, as that tariff.
     */
    private function startPaidSession(ComputerReservation $reservation, ClubComputer $computer, bool $byCode = false): bool
    {
        $own = $computer->hasPaidSession();
        if ($own && ($byCode || $computer->session_guest || $computer->current_session_user_id !== $reservation->user_id)) {
            return false;
        }
        $duration = $reservation->durationMinutes();
        $minutes = min($duration, intdiv(max(0, $reservation->ends_at->getTimestamp() - now()->getTimestamp()) + 59, 60));
        if ($minutes <= 0) {
            return false;
        }
        $sessionId = $own ? $computer->current_session_id : (string) Str::uuid();
        $endsAt = ($own ? $computer->session_ends_at->copy() : now())->addMinutes($minutes);

        if ($byCode) {
            // Someone with the code, without an account: the agent opens the PC as for time sold at
            // the cash register; the time was paid by the booking and is not refunded.
            $computer->update([
                'current_session_id' => (string) Str::uuid(),
                'current_session_user_id' => null,
                'session_guest' => true,
                'session_ends_at' => now()->addMinutes($minutes),
            ]);
            $reservation->update(['arrived_at' => now()]);

            return true;
        }

        // Booked as a tariff, the time is that tariff's (not refunded; a usage limit keeps the rest).
        $tariff = $reservation->tariff_id === null ? null : Tariff::query()->find($reservation->tariff_id);
        SessionPurchase::query()->create([
            'session_id' => $sessionId,
            'user_id' => $reservation->user_id,
            'kind' => $tariff === null ? SessionPurchase::KIND_MINUTES : SessionPurchase::KIND_TARIFF,
            'tariff_id' => $tariff?->getKey(),
            'name' => $tariff?->name ?? __('Бронь'),
            'minutes' => $minutes,
            'main_cents' => intdiv($reservation->paid_main_cents * $minutes, $duration),
            'bonus_cents' => intdiv($reservation->paid_bonus_cents * $minutes, $duration),
            'expires_at' => $tariff?->usage_limit_enabled && $tariff->usage_limit_minutes !== null ? now()->addMinutes($tariff->usage_limit_minutes) : null,
        ]);
        $computer->update([
            'current_session_id' => $sessionId,
            'current_session_user_id' => $reservation->user_id,
            'session_guest' => false,
            'session_ends_at' => $endsAt,
        ]);
        $reservation->update(['arrived_at' => now()]);

        return true;
    }

    private function recordArrival(ComputerReservation $reservation, ClubComputer $computer, int $actorId): void
    {
        $names = ['computer' => $computer->displayName(), 'from' => $this->local($reservation->starts_at)->format('d.m.Y H:i')];
        $this->activity->record('reservation.arrived', __('Клиент пришёл по брони ПК :computer на :from', $names), $actorId, $reservation, [
            'computer' => $names['computer'],
            'starts_at' => $names['from'],
        ]);
    }

    /** The paid amount goes back to the balances it was paid from. */
    private function refund(ComputerReservation $reservation, User $actor): void
    {
        $balance = MemberBalance::query()->where('user_id', $reservation->user_id)->lockForUpdate()->first();
        if ($balance === null) {
            return;
        }
        $balance->update([
            'balance' => AgentTariffPurchaseService::formatCents(AgentTariffPurchaseService::amountToCents((string) $balance->balance) + $reservation->paid_main_cents),
            'bonus_balance' => AgentTariffPurchaseService::formatCents(AgentTariffPurchaseService::amountToCents((string) $balance->bonus_balance) + $reservation->paid_bonus_cents),
        ]);
        $this->loyalty->subtractSpending($reservation->user_id, $reservation->paid_main_cents);
        $this->activity->record('reservation.refunded', __('Возврат за отменённую бронь ПК :computer', ['computer' => $reservation->computer?->displayName() ?? '—']), $actor->getKey(), User::query()->find($reservation->user_id), [
            'main_balance_amount' => AgentTariffPurchaseService::formatCents($reservation->paid_main_cents),
            'bonus_balance_amount' => AgentTariffPurchaseService::formatCents($reservation->paid_bonus_cents),
            'reservation_id' => $reservation->getKey(),
        ]);
    }
}
