<?php

namespace App\Services;

use App\Models\ClubMembership;
use App\Support\ClubRoles;
use Illuminate\Support\Facades\DB;

/**
 * Cashiers and operators "on shift" are those signed in to the panel now (a live web
 * session). A club may limit how many of each role are on shift at once (settings → club).
 */
class StaffShifts
{
    public const SETTINGS = ['cashier' => 'max_cashiers_on_shift', 'operator' => 'max_operators_on_shift'];

    /** Limit for the role in the club; null — no limit. */
    public function limit(array $clubSettings, string $role): ?int
    {
        $value = $clubSettings['club'][self::SETTINGS[$role] ?? ''] ?? 0;

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /** User ids of the club's members with the role who have a live panel session, except one. */
    public function onShift(int $clubId, string $role, ?int $exceptUserId = null): array
    {
        if (config('session.driver') !== 'database') {
            return [];
        }

        $since = now()->subMinutes((int) config('session.lifetime'))->getTimestamp();
        $members = ClubMembership::query()->withoutGlobalScope('club')
            ->where('club_id', $clubId)->where('role', $role)
            ->select('user_id');

        return DB::table(config('session.table', 'sessions'))
            ->whereIn('user_id', $members)
            ->where('last_activity', '>=', $since)
            ->when($exceptUserId, fn ($query) => $query->where('user_id', '!=', $exceptUserId))
            ->distinct()
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /** Whether this staff member may sign in now: a free place on the shift of their role. */
    public function hasPlace(int $clubId, array $clubSettings, string $role, int $userId): bool
    {
        if (! in_array($role, ClubRoles::SHIFT, true)) {
            return true;
        }

        $limit = $this->limit($clubSettings, $role);

        return $limit === null || count($this->onShift($clubId, $role, $userId)) < $limit;
    }
}
