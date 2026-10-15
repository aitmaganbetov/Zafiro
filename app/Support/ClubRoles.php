<?php

namespace App\Support;

/** Roles of club members: staff work in the panel, everyone else is a customer of the club. */
final class ClubRoles
{
    public const STAFF = ['owner', 'admin', 'manager', 'cashier', 'operator'];

    /** Roles on a shift whose number at once the club can limit (settings → club). */
    public const SHIFT = ['cashier', 'operator'];

    /** New customers get this role; older customers may have other non-staff roles. */
    public const CLIENT = 'client';

    public static function isStaff(?string $role): bool
    {
        return in_array($role, self::STAFF, true);
    }

    /** @return array<string, string> role => label (staff roles a club can assign) */
    public static function assignable(): array
    {
        return [
            'admin' => __('Администратор'),
            'manager' => __('Менеджер'),
            'cashier' => __('Кассир'),
            'operator' => __('Оператор'),
        ];
    }

    public static function label(?string $role): string
    {
        return match ($role) {
            'owner' => __('Владелец'),
            'admin' => __('Администратор'),
            'manager' => __('Менеджер'),
            'cashier' => __('Кассир'),
            'operator' => __('Оператор'),
            default => __('Клиент'),
        };
    }
}
