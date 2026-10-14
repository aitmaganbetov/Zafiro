<?php

namespace App\Services;

use App\Models\ClubComputer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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
    private const SESSION_FIELDS = ['current_session_id' => null, 'current_session_user_id' => null, 'session_ends_at' => null];

    public function __construct(private ActivityLogger $activity) {}

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
            $updates = ['last_seen_at' => now(), 'status' => 'online', 'remote_ip' => $ip];

            if (array_key_exists('state', $data)) {
                // Right after expiry the agent may still report the old session: it is over.
                $state = $expired && $data['state'] === 'in_session' ? 'idle' : $data['state'];
                $updates['agent_state'] = $state;
                $updates += $this->sessionUpdates($computer, $state, $data['session_id'] ?? null);
            }

            foreach (['agent_version', 'mac_address', 'local_ip', 'cpu_model', 'cpu_cores', 'ram_total_mb', 'gpu_model', 'os_version'] as $field) {
                if (array_key_exists($field, $data)) {
                    $updates[$field] = $data[$field];
                }
            }

            if (! empty($data['device_name'])) {
                $updates['device_name'] = $data['device_name'];
            }

            if (array_intersect(['mac_address', 'local_ip', 'cpu_model', 'cpu_cores', 'ram_total_mb', 'gpu_model', 'os_version'], array_keys($data)) !== []) {
                $updates['hardware_reported_at'] = now();
            }

            $computer->update($updates);

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
            ? ['current_session_id' => $sessionId, 'current_session_user_id' => null, 'session_ends_at' => null]
            : self::SESSION_FIELDS;
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

    /** The customer who owns the session or club staff end it early; unused time is not refunded. */
    public function end(ClubComputer $computer, string $sessionId, User $actor, bool $isStaff): void
    {
        DB::transaction(function () use ($computer, $sessionId, $actor, $isStaff): void {
            $computer = ClubComputer::query()->lockForUpdate()->findOrFail($computer->getKey());

            if ($computer->current_session_id === null || $computer->current_session_id !== $sessionId) {
                throw ValidationException::withMessages(['session_id' => __('На компьютере нет такой активной сессии.')]);
            }

            if (! $isStaff && $computer->current_session_user_id !== $actor->getKey()) {
                abort(403, __('Завершить сессию может только её владелец или сотрудник клуба.'));
            }

            $endsAt = $computer->session_ends_at;
            $computer->forceFill(self::SESSION_FIELDS)->save();
            $this->activity->record('computer.session_ended', __('Сессия на ПК :computer завершена досрочно', ['computer' => $computer->displayName()]), $actor->getKey(), $computer, [
                'session_id' => $sessionId,
                'planned_end' => $endsAt?->utc()->toIso8601String() ?? '—',
            ]);
        });
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
        ];
    }
}
