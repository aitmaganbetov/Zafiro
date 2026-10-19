<?php

namespace App\Services;

use App\Models\Club;
use App\Models\ClubComputer;
use App\Models\ClubMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Sessions of club PCs. The server is the only source of truth for a paid session:
 * it starts with a tariff purchase, its end time is never taken from the agent, an
 * agent state change (idle, locked, restart) does not erase it, and it ends when its
 * time runs out or on an explicit end request.
 */
class ClubComputerSessions
{
    public const DEFAULT_STAFF_MINUTES = 15;

    /** Club roles that may set up PCs and count as staff on them. */
    public const STAFF_ROLES = ['owner', 'admin', 'manager', 'operator'];

    private const SESSION_FIELDS = ['current_session_id' => null, 'current_session_user_id' => null, 'session_guest' => false, 'session_ends_at' => null];

    private const STAFF_FIELDS = ['staff_user_id' => null, 'staff_kind' => null, 'staff_until' => null];

    /** Agent states that mean the PC is back in customer hands. */
    private const CUSTOMER_STATES = ['idle', 'locked', 'in_session'];

    public function __construct(private ActivityLogger $activity, private SessionSettlement $settlement) {}

    /**
     * Apply an agent heartbeat and describe the computer for the agent.
     *
     * @param  array<string, mixed>  $data  validated heartbeat fields
     * @return array<string, mixed> fields to merge into the response
     */
    public function heartbeat(ClubComputer $computer, array $data, ?string $ip): array
    {
        return DB::transaction(function () use ($computer, $data, $ip): array {
            $computer = ClubComputer::query()->withoutGlobalScope('club')->lockForUpdate()->findOrFail($computer->getKey());
            $expired = $this->expireIfDue($computer);
            if (filter_var($data['fresh_boot'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $this->resetGuestSession($computer);
            }
            $updates = ['last_seen_at' => now(), 'status' => 'online', 'remote_ip' => $ip];

            if (array_key_exists('state', $data)) {
                // Right after expiry the agent may still report the old session: it is over.
                $state = $expired && $data['state'] === 'in_session' ? 'idle' : $data['state'];
                $updates['agent_state'] = $state;
                $updates += $this->sessionUpdates($computer, $state, $data['session_id'] ?? null);
                if (in_array($state, self::CUSTOMER_STATES, true)) {
                    $updates += self::STAFF_FIELDS;
                }
            }

            foreach (['agent_version', 'local_ip', 'cpu_model', 'cpu_cores', 'ram_total_mb', 'gpu_model', 'os_version'] as $field) {
                if (array_key_exists($field, $data)) {
                    $updates[$field] = $data[$field];
                }
            }

            // The MAC is the key of the card: a heartbeat only fills it in. Another MAC means
            // another PC (one booted from this PC's image with its token): it does not take the card.
            if (($data['mac_address'] ?? null) !== null) {
                $taken = $computer->mac_address === null && ClubComputer::query()->withoutGlobalScope('club')
                    ->where('club_id', $computer->club_id)->where('mac_address', $data['mac_address'])->exists();
                if ($computer->mac_address === null && ! $taken) {
                    $updates['mac_address'] = $data['mac_address'];
                } elseif ($computer->mac_address !== $data['mac_address']) {
                    Log::warning('Agent heartbeat with another MAC ignored', ['club_id' => $computer->club_id, 'computer_id' => $computer->getKey()]);
                }
            }

            if (! empty($data['device_name'])) {
                $updates['device_name'] = $data['device_name'];
            }

            if (array_intersect(['mac_address', 'local_ip', 'cpu_model', 'cpu_cores', 'ram_total_mb', 'gpu_model', 'os_version'], array_keys($data)) !== []) {
                $updates['hardware_reported_at'] = now();
            }

            // forceFill: the staff fields are server-only and deliberately not mass assignable.
            $computer->forceFill($updates)->save();

            return ['computer' => $computer, 'session' => $this->sessionPayload($computer), 'session_expired' => $expired];
        });
    }

    /**
     * What a reported agent state may change. A paid session is never changed by the
     * agent: its id must match and its end time stays the server's. Without a paid
     * session the agent may report its own session id (older agents), with no end time.
     *
     * @return array<string, mixed>
     */
    private function sessionUpdates(ClubComputer $computer, string $state, ?string $sessionId): array
    {
        if ($computer->hasPaidSession()) {
            if ($state === 'in_session' && $sessionId !== $computer->current_session_id) {
                throw ValidationException::withMessages(['session_id' => __('Идентификатор сессии не совпадает с активной сессией компьютера.')]);
            }

            return [];
        }

        return $state === 'in_session'
            ? ['current_session_id' => $sessionId, 'current_session_user_id' => null, 'session_guest' => false, 'session_ends_at' => null]
            : self::SESSION_FIELDS;
    }

    /**
     * The agent's first report after Windows started (or the user signed in again): time sold to a
     * guest without an account does not survive a restart of the PC.
     */
    private function resetGuestSession(ClubComputer $computer): void
    {
        if (! $computer->session_guest || ! $computer->hasPaidSession()) {
            return;
        }

        $computer->forceFill(self::SESSION_FIELDS)->save();
        // Logged as the club owner: an activity entry needs a member of the club.
        $ownerId = ClubMembership::query()->withoutGlobalScope('club')->where('club_id', $computer->club_id)->where('role', 'owner')->orderBy('id')->value('user_id');
        if ($ownerId !== null) {
            $this->activity->record('computer.guest_session_reset', __('Гостевая сессия на ПК :computer сброшена: ПК перезагружен.', ['computer' => $computer->displayName()]), (int) $ownerId, $computer, [], $computer->club_id);
        }
    }

    /** Ends a paid session whose time is over. Returns true when one was ended. */
    public function expireIfDue(ClubComputer $computer): bool
    {
        if ($computer->session_ends_at === null || $computer->session_ends_at->isFuture()) {
            return false;
        }

        $computer->forceFill(self::SESSION_FIELDS)->save();

        return true;
    }

    /** Ends every paid session whose time is over (also for PCs that went offline). */
    public function expireAll(): int
    {
        return ClubComputer::query()->withoutGlobalScope('club')
            ->whereNotNull('session_ends_at')
            ->where('session_ends_at', '<=', now())
            ->update(self::SESSION_FIELDS + ['updated_at' => now()]);
    }

    /**
     * The customer who owns the session or club staff end it early. Unused minutes go back to the
     * balances, unused time of a tariff with a usage limit is kept as a package (SessionSettlement).
     *
     * @return array{refund_main: int, refund_bonus: int, kept_minutes: int}
     */
    public function end(ClubComputer $computer, string $sessionId, User $actor, bool $isStaff): array
    {
        return DB::transaction(function () use ($computer, $sessionId, $actor, $isStaff): array {
            $computer = ClubComputer::query()->lockForUpdate()->findOrFail($computer->getKey());

            if ($computer->current_session_id === null || $computer->current_session_id !== $sessionId) {
                throw ValidationException::withMessages(['session_id' => __('На компьютере нет такой активной сессии.')]);
            }

            if (! $isStaff && $computer->current_session_user_id !== $actor->getKey()) {
                abort(403, __('Завершить сессию может только её владелец или сотрудник клуба.'));
            }

            $endsAt = $computer->session_ends_at;
            $customerId = $computer->current_session_user_id;
            $computer->forceFill(self::SESSION_FIELDS)->save();
            $settled = $customerId === null
                ? ['refund_main' => 0, 'refund_bonus' => 0, 'kept_minutes' => 0]
                : $this->settlement->settle($computer, $sessionId, (int) $customerId, $endsAt, $actor->getKey());
            $this->activity->record('computer.session_ended', __('Сессия на ПК :computer завершена досрочно', ['computer' => $computer->displayName()]), $actor->getKey(), $computer, [
                'session_id' => $sessionId,
                'planned_end' => $endsAt?->utc()->toIso8601String() ?? '—',
            ]);

            return $settled;
        });
    }

    /**
     * Staff started working on the PC: kind "pin" (agent closed with the staff PIN) or
     * "signin" (a staff member signed in on it). Lasts the club's agent_staff_minutes or
     * until the agent reports a customer state again. Returns the minutes.
     */
    public function startStaffVisit(ClubComputer $computer, ?User $staff, string $kind): int
    {
        $minutes = (int) (Club::query()->whereKey($computer->club_id)->value('agent_staff_minutes') ?? self::DEFAULT_STAFF_MINUTES);
        $computer->forceFill(['staff_user_id' => $staff?->getKey(), 'staff_kind' => $kind, 'staff_until' => now()->addMinutes($minutes)])->save();

        return $minutes;
    }

    /** New device token for the agent of this PC; the previous one stops working. */
    public function issueAgentToken(ClubComputer $computer): string
    {
        $token = 'zag_'.Str::random(48);
        $computer->forceFill(['agent_token_hash' => hash('sha256', $token), 'agent_token_issued_at' => now()])->save();

        return $token;
    }

    public function findByAgentToken(string $token): ?ClubComputer
    {
        if (preg_match('/^zag_[A-Za-z0-9]{48}$/', $token) !== 1) {
            return null;
        }

        return ClubComputer::query()->withoutGlobalScope('club')->where('agent_token_hash', hash('sha256', $token))->first();
    }

    /** The paid session the agent must show and enforce, or null. */
    public function sessionPayload(ClubComputer $computer): ?array
    {
        if (! $computer->hasPaidSession()) {
            return null;
        }

        return [
            'id' => $computer->current_session_id,
            'ends_at' => $computer->session_ends_at?->utc()->toIso8601String(),
            'user_id' => $computer->current_session_user_id,
            // Paid at the cash register without an account: the agent unlocks the PC without a sign-in.
            'guest' => $computer->session_guest === true,
        ];
    }
}
