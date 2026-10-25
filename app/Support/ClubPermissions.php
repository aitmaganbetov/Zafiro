<?php

namespace App\Support;

use App\Models\ClubMembership;

/**
 * What a staff member may do in the panel: a section and an action ("warehouse.create"). Each
 * right has the roles that have it by default (the rules before rights existed); the owner (or
 * an admin) gives or takes it from one employee in Settings → Rights
 * (club_memberships.permissions: {"section.action": true|false}). The owner has every right.
 */
final class ClubPermissions
{
    public const ACTIONS = ['view', 'create', 'update', 'delete'];

    private const STAFF = ['admin', 'manager', 'cashier', 'operator'];

    private const DESK = ['admin', 'manager', 'cashier'];

    private const MANAGERS = ['admin', 'manager'];

    /** section => action => roles that have it unless set for the employee (an action not listed does not exist) */
    public const SECTIONS = [
        'cash' => ['view' => self::DESK, 'create' => self::DESK, 'delete' => self::MANAGERS],
        'customers' => ['view' => self::STAFF, 'create' => self::STAFF, 'update' => self::STAFF],
        'customer_money' => ['update' => self::MANAGERS],
        'reservations' => ['view' => self::STAFF, 'create' => self::STAFF, 'update' => self::STAFF, 'delete' => self::STAFF],
        'club_map' => ['view' => self::STAFF, 'create' => self::MANAGERS, 'update' => self::MANAGERS, 'delete' => self::MANAGERS],
        // Ending a customer's session: as in the staff app (ClubComputerSessions::STAFF_ROLES).
        'pc_control' => ['update' => self::STAFF, 'delete' => ['admin', 'manager', 'operator']],
        'tariffs' => ['view' => self::MANAGERS, 'create' => self::MANAGERS, 'update' => self::MANAGERS, 'delete' => []],
        'warehouse' => ['view' => self::MANAGERS, 'create' => self::MANAGERS, 'update' => self::MANAGERS, 'delete' => []],
        'stock' => ['view' => self::STAFF, 'create' => self::STAFF],
        'orders' => ['view' => self::STAFF, 'update' => self::STAFF, 'delete' => self::STAFF],
        // Customers are answered by the front desk (ChatMessage::ANSWERING_ROLES); others read.
        'chat' => ['view' => self::STAFF, 'create' => ['cashier', 'operator']],
        'todos' => ['view' => self::STAFF, 'create' => self::MANAGERS, 'update' => self::STAFF, 'delete' => self::MANAGERS],
        'news' => ['view' => self::MANAGERS, 'create' => self::MANAGERS, 'update' => self::MANAGERS, 'delete' => self::MANAGERS],
        'activity_logs' => ['view' => self::MANAGERS],
        'settings' => ['view' => self::MANAGERS, 'update' => self::MANAGERS],
        'workers' => ['view' => ['admin'], 'create' => ['admin'], 'update' => ['admin'], 'delete' => ['admin']],
        'billing' => ['view' => self::MANAGERS],
    ];

    /** @param  string  $right  "section.action" */
    public static function allows(?ClubMembership $membership, string $right): bool
    {
        if ($membership === null || ! self::exists($right)) {
            return false;
        }
        if ($membership->role === 'owner') {
            return true;
        }

        return self::given($membership, $right) ?? self::byRole($membership->role, $right);
    }

    public static function exists(string $right): bool
    {
        [$section, $action] = array_pad(explode('.', $right, 2), 2, '');

        return isset(self::SECTIONS[$section][$action]);
    }

    public static function byRole(?string $role, string $right): bool
    {
        [$section, $action] = array_pad(explode('.', $right, 2), 2, '');

        return $role === 'owner' || in_array($role, self::SECTIONS[$section][$action] ?? [], true);
    }

    /** The choice made for the employee; null — as the role has it. */
    public static function given(ClubMembership $membership, string $right): ?bool
    {
        $value = ($membership->permissions ?? [])[$right] ?? null;

        return is_bool($value) ? $value : null;
    }

    /** @return list<string> every "section.action" */
    public static function all(): array
    {
        $rights = [];
        foreach (self::SECTIONS as $section => $actions) {
            foreach (array_keys($actions) as $action) {
                $rights[] = $section.'.'.$action;
            }
        }

        return $rights;
    }

    /** @return array<string, string> action => column title */
    public static function actionLabels(): array
    {
        return ['view' => __('Просмотр'), 'create' => __('Создание'), 'update' => __('Изменение'), 'delete' => __('Удаление')];
    }

    /**
     * @return array<string, array{label: string, actions: array<string, string>}> section => its title and
     *                                                                             what each of its actions means
     */
    public static function sections(): array
    {
        return [
            'cash' => ['label' => __('Касса'), 'actions' => ['view' => __('Касса, чеки, X/Z-отчёты'), 'create' => __('Смены, продажи, пополнения, приход и расход, тариф гостю'), 'delete' => __('Возвраты')]],
            'customers' => ['label' => __('Клиенты'), 'actions' => ['view' => __('Список клиентов'), 'create' => __('Добавить клиента'), 'update' => __('Изменить клиента, сбросить пароль')]],
            'customer_money' => ['label' => __('Скидка и баланс клиента'), 'actions' => ['update' => __('Персональная скидка и начальный баланс')]],
            'reservations' => ['label' => __('Брони'), 'actions' => ['view' => __('Список броней'), 'create' => __('Забронировать ПК'), 'update' => __('Отметить приход'), 'delete' => __('Отменить бронь')]],
            'club_map' => ['label' => __('Карта клуба и ПК'), 'actions' => ['view' => __('Карта и список ПК'), 'create' => __('Добавить зону или ПК'), 'update' => __('Изменить зоны, ПК, расстановку'), 'delete' => __('Удалить зону или ПК')]],
            'pc_control' => ['label' => __('Управление ПК'), 'actions' => ['update' => __('Включить, выключить, перезагрузить, забронировать, техрежим'), 'delete' => __('Завершить сессию клиента')]],
            'tariffs' => ['label' => __('Тарифы'), 'actions' => ['view' => __('Список тарифов'), 'create' => __('Добавить тариф'), 'update' => __('Изменить тариф'), 'delete' => __('Удалить тариф')]],
            'warehouse' => ['label' => __('Склад: каталог'), 'actions' => ['view' => __('Каталог товаров'), 'create' => __('Добавить товар или категорию'), 'update' => __('Изменить товар, списать или оприходовать'), 'delete' => __('Удалить товар')]],
            'stock' => ['label' => __('Приход товара'), 'actions' => ['view' => __('Остатки на складе'), 'create' => __('Принять товар')]],
            'orders' => ['label' => __('Заказы из агента'), 'actions' => ['view' => __('Список заказов'), 'update' => __('Отметить «Выдан»'), 'delete' => __('Отменить заказ')]],
            'chat' => ['label' => __('Чат с клиентами'), 'actions' => ['view' => __('Читать чат'), 'create' => __('Отвечать')]],
            'todos' => ['label' => __('To Do List'), 'actions' => ['view' => __('Список задач'), 'create' => __('Добавить задачу'), 'update' => __('Отметить выполнение'), 'delete' => __('Удалить задачу')]],
            'news' => ['label' => __('Новости'), 'actions' => ['view' => __('Список новостей'), 'create' => __('Добавить новость'), 'update' => __('Изменить новость'), 'delete' => __('Удалить новость')]],
            'activity_logs' => ['label' => __('Журнал действий'), 'actions' => ['view' => __('Логи клуба')]],
            'settings' => ['label' => __('Настройки клуба'), 'actions' => ['view' => __('Открыть настройки'), 'update' => __('Сохранить настройки, логотип, картинку')]],
            'workers' => ['label' => __('Работники'), 'actions' => ['view' => __('Список сотрудников'), 'create' => __('Добавить или пригласить'), 'update' => __('Изменить роль, PIN-код агента'), 'delete' => __('Убрать сотрудника')]],
            'billing' => ['label' => __('Подписка'), 'actions' => ['view' => __('Тариф Zafiro и оплата')]],
        ];
    }
}
