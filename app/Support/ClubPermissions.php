<?php

namespace App\Support;

use App\Models\ClubMembership;

/**
 * What a staff member may do in the panel. Each right has the roles that have it by default
 * (the rules before rights existed); the owner gives or takes it from one employee on the
 * "Rights" settings page (club_memberships.permissions: {right: true|false}). The owner has all.
 */
final class ClubPermissions
{
    /** right => roles that have it unless the owner set it for the employee */
    public const DEFAULTS = [
        'cash_register' => ['admin', 'manager', 'cashier'],
        'cash_refunds' => ['admin', 'manager'],
        'customer_money' => ['admin', 'manager'],
        'club_map_edit' => ['admin', 'manager'],
        'tariffs' => ['admin', 'manager'],
        'warehouse' => ['admin', 'manager'],
        'activity_logs' => ['admin', 'manager'],
        'settings' => ['admin', 'manager'],
        'news' => ['admin', 'manager'],
        'todos_manage' => ['admin', 'manager'],
        'billing' => ['admin', 'manager'],
        'workers' => ['admin'],
    ];

    public static function allows(?ClubMembership $membership, string $right): bool
    {
        if ($membership === null || ! array_key_exists($right, self::DEFAULTS)) {
            return false;
        }
        if ($membership->role === 'owner') {
            return true;
        }

        return self::given($membership, $right) ?? self::byRole($membership->role, $right);
    }

    public static function byRole(?string $role, string $right): bool
    {
        return $role === 'owner' || in_array($role, self::DEFAULTS[$right] ?? [], true);
    }

    /** The owner's own choice for the employee; null — as the role has it. */
    public static function given(ClubMembership $membership, string $right): ?bool
    {
        $value = ($membership->permissions ?? [])[$right] ?? null;

        return is_bool($value) ? $value : null;
    }

    /** @return array<string, array{label: string, hint: string}> */
    public static function labels(): array
    {
        return [
            'cash_register' => ['label' => __('Касса'), 'hint' => __('Смены, продажи, пополнения, X/Z-отчёты, подключение тарифа гостю.')],
            'cash_refunds' => ['label' => __('Возвраты в кассе'), 'hint' => __('Возврат продажи или пополнения баланса.')],
            'customer_money' => ['label' => __('Скидка и баланс клиента'), 'hint' => __('Персональная скидка и начальный баланс нового клиента.')],
            'club_map_edit' => ['label' => __('Карта клуба и ПК'), 'hint' => __('Зоны, расстановка, добавление и удаление ПК.')],
            'tariffs' => ['label' => __('Тарифы'), 'hint' => __('Создание и изменение тарифов.')],
            'warehouse' => ['label' => __('Склад'), 'hint' => __('Каталог товаров, категории и движение товара.')],
            'activity_logs' => ['label' => __('Журнал действий'), 'hint' => __('Просмотр логов клуба.')],
            'settings' => ['label' => __('Настройки клуба'), 'hint' => __('Сессии, агент, финансы, гости, языки.')],
            'news' => ['label' => __('Новости'), 'hint' => __('Новости клуба на экране агента.')],
            'todos_manage' => ['label' => __('Задачи'), 'hint' => __('Создание и удаление задач в To Do List.')],
            'billing' => ['label' => __('Подписка'), 'hint' => __('Тариф Zafiro и оплата.')],
            'workers' => ['label' => __('Работники'), 'hint' => __('Сотрудники клуба, PIN-код и время сотрудника на ПК.')],
        ];
    }
}
