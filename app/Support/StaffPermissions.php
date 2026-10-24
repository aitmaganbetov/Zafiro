<?php

namespace App\Support;

use App\Models\ChatMessage;
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
        $cash = ClubPermissions::allows($membership, 'cash_register');

        return [
            'cash' => $cash,
            'guest_session' => $guestMode && $cash,
            'end_session' => $is(ClubComputerSessions::STAFF_ROLES),
            'control' => ClubRoles::isStaff($role),
            'chat_reply' => $is(ChatMessage::ANSWERING_ROLES),
            'stock_receive' => ClubRoles::isStaff($role),
            'stock_manage' => ClubPermissions::allows($membership, 'warehouse'),
            'reports' => $is(self::REPORTS),
        ];
    }
}
