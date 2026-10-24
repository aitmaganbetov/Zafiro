<?php

namespace App\Support;

use App\Models\ClubMembership;
use App\Services\ClubComputerSessions;

/**
 * What a staff member may do in the staff app — the same rules as the routes of the web panel
 * (routes/web.php): the cash register and the warehouse by the employee's rights
 * (ClubPermissions, Settings → Rights), so the app shows only what the server will allow.
 */
final class StaffPermissions
{
    public const REPORTS = ['owner', 'admin', 'manager'];

    /** @return array<string, bool> */
    public static function for(?ClubMembership $membership, bool $guestMode): array
    {
        $role = $membership?->role;
        $is = fn (array $roles): bool => in_array($role, $roles, true);
        $cash = ClubPermissions::allows($membership, 'cash.view') && ClubPermissions::allows($membership, 'cash.create');

        return [
            'cash' => $cash,
            'guest_session' => $guestMode && ClubPermissions::allows($membership, 'cash.create'),
            'end_session' => $is(ClubComputerSessions::STAFF_ROLES),
            'control' => ClubPermissions::allows($membership, 'pc_control.update'),
            'chat_reply' => ClubPermissions::allows($membership, 'chat.create'),
            'stock_receive' => ClubPermissions::allows($membership, 'stock.create'),
            'stock_manage' => ClubPermissions::allows($membership, 'warehouse.update'),
            'reports' => $is(self::REPORTS),
        ];
    }
}
