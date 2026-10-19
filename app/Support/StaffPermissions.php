<?php

namespace App\Support;

use App\Models\ChatMessage;
use App\Services\ClubComputerSessions;

/**
 * What a staff role may do in the staff app — the same rules as the routes of the web panel
 * (routes/web.php), so the app shows only what the server will allow.
 */
final class StaffPermissions
{
    /** Roles per permission; the routes of /api/staff use the same lists. */
    public const CASH = ['owner', 'admin', 'manager', 'cashier'];

    public const STOCK_MANAGE = ['owner', 'admin', 'manager'];

    public const REPORTS = ['owner', 'admin', 'manager'];

    /** @return array<string, bool> */
    public static function for(?string $role, bool $guestMode): array
    {
        $is = fn (array $roles): bool => in_array($role, $roles, true);

        return [
            'cash' => $is(self::CASH),
            'guest_session' => $guestMode && $is(self::CASH),
            'end_session' => $is(ClubComputerSessions::STAFF_ROLES),
            'control' => ClubRoles::isStaff($role),
            'chat_reply' => $is(ChatMessage::ANSWERING_ROLES),
            'stock_receive' => ClubRoles::isStaff($role),
            'stock_manage' => $is(self::STOCK_MANAGE),
            'reports' => $is(self::REPORTS),
        ];
    }
}
