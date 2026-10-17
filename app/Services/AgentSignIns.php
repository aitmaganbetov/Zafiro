<?php

namespace App\Services;

use App\Models\User;

/**
 * A customer is signed in on one PC at a time. Signed in elsewhere = a live agent token for another
 * PC that the agent used in the last minutes (it calls the server every ~20 s; signing out deletes
 * the token). A PC switched off without signing out frees the account after these minutes.
 */
class AgentSignIns
{
    public const ACTIVE_MINUTES = 2;

    /** Tokens of the mobile app, not of a PC. */
    private const NOT_A_PC = 'mobile';

    /** Name of the other PC the customer is signed in on now; null — nowhere else. Staff are not limited. */
    public function elsewhere(User $user, ?string $deviceName, ?string $role): ?string
    {
        if (in_array($role, [...ClubComputerSessions::STAFF_ROLES, 'cashier'], true)) {
            return null;
        }

        return $user->tokens()
            ->where('name', '!=', self::NOT_A_PC)
            ->when($deviceName !== null && $deviceName !== '', fn ($query) => $query->where('name', '!=', $deviceName))
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where('last_used_at', '>=', now()->subMinutes(self::ACTIVE_MINUTES))
            ->latest('last_used_at')
            ->value('name');
    }

    public static function message(string $computer): string
    {
        return __('Вы уже вошли на другом ПК (:computer). Выйдите там, чтобы войти здесь.', ['computer' => $computer]);
    }
}
