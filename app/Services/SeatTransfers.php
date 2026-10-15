<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ClubComputer;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A customer moves the rest of their paid session to another free PC of the club. The PC is
 * free when it is online, has no session, no staff on it, is not reserved and no booking of
 * someone else runs into the rest of the session; the session's tariff must be valid in its zone.
 */
class SeatTransfers
{
    public function __construct(private ActivityLogger $activity, private ComputerReservations $reservations) {}

    /** @return Collection<int, ClubComputer> */
    public function freeSeatsFor(User $user, ClubComputer $from): Collection
    {
        $this->ensureOwnSession($user, $from);
        $zones = $this->allowedZoneIds($from);
        $held = $this->reservations->heldComputerIds();

        return ClubComputer::query()
            ->with('zone:id,name')
            ->whereKeyNot($from->getKey())
            ->where('is_reserved', false)
            ->where('last_seen_at', '>=', now()->subSeconds(ClubComputer::HEARTBEAT_TIMEOUT_SECONDS))
            ->where(fn ($query) => $query->whereNull('session_ends_at')->orWhere('session_ends_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('staff_until')->orWhere('staff_until', '<=', now()))
            ->when($zones !== null, fn ($query) => $query->whereIn('club_zone_id', $zones))
            ->whereNotIn('id', $held)
            ->orderBy('club_zone_id')->orderBy('number')
            ->limit(200)
            ->get()
            ->reject(fn (ClubComputer $computer): bool => $this->reservations->blocking($computer, $user->getKey(), $from->session_ends_at) !== null)
            ->values();
    }

    public function transfer(User $user, ClubComputer $from, int $targetId): ClubComputer
    {
        return DB::transaction(function () use ($user, $from, $targetId): ClubComputer {
            // Both PCs are locked in one order, so two transfers cannot deadlock.
            $locked = ClubComputer::query()->whereKey([$from->getKey(), $targetId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $from = $locked->get($from->getKey());
            $target = $locked->get($targetId);
            if ($from === null || $target === null || $target->is($from)) {
                throw ValidationException::withMessages(['target_computer_id' => __('Выберите другой свободный ПК.')]);
            }

            $this->ensureOwnSession($user, $from);
            $free = ! $target->is_reserved
                && $target->connectionStatus() === 'online'
                && ! $target->hasPaidSession()
                && ! $target->hasStaffVisit()
                && ! in_array($target->getKey(), $this->reservations->heldComputerIds(), true)
                && $this->reservations->blocking($target, $user->getKey(), $from->session_ends_at) === null;
            $zones = $this->allowedZoneIds($from);
            if (! $free || ($zones !== null && ! in_array((int) $target->club_zone_id, $zones, true))) {
                throw ValidationException::withMessages(['target_computer_id' => __('Этот ПК уже занят или ваш тариф на нём не действует. Выберите другой.')]);
            }

            $session = ['current_session_id' => $from->current_session_id, 'current_session_user_id' => $user->getKey(), 'session_ends_at' => $from->session_ends_at];
            $from->forceFill(['current_session_id' => null, 'current_session_user_id' => null, 'session_ends_at' => null])->save();
            $target->forceFill($session)->save();
            $this->activity->record('session.transferred', __(':user пересел с ПК :from на ПК :to', [
                'user' => $user->name,
                'from' => $from->displayName(),
                'to' => $target->displayName(),
            ]), $user->getKey(), $target, [
                'from' => $from->displayName(),
                'to' => $target->displayName(),
                'session_id' => $session['current_session_id'],
            ]);

            return $target;
        });
    }

    private function ensureOwnSession(User $user, ClubComputer $computer): void
    {
        if (! $computer->hasPaidSession() || $computer->current_session_user_id !== $user->getKey()) {
            throw ValidationException::withMessages(['computer_id' => __('На этом ПК нет вашей оплаченной сессии.')]);
        }
    }

    /**
     * Zones where the session's last tariff is valid; null — every zone.
     *
     * @return list<int>|null
     */
    private function allowedZoneIds(ClubComputer $computer): ?array
    {
        $tariffId = ActivityLog::query()
            ->where('event', 'tariff.purchased')
            ->where('subject_type', (new Tariff)->getMorphClass())
            ->where('details->session_id', $computer->current_session_id)
            ->latest('id')
            ->value('subject_id');
        $tariff = $tariffId === null ? null : Tariff::query()->with('zones:id')->find($tariffId);
        if ($tariff === null || $tariff->zones->isEmpty()) {
            return null;
        }

        return $tariff->zones->modelKeys();
    }
}
