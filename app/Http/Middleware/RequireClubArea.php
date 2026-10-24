<?php

namespace App\Http\Middleware;

use App\Services\CurrentClub;
use App\Support\ClubPermissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The right ("section.action", ClubPermissions) a panel route needs, by its name. A route of a
 * guarded group that is not listed here is refused: a new page gets its right here first.
 */
class RequireClubArea
{
    /** right => route names */
    public const ROUTES = [
        'cash.view' => ['cash-register.index', 'cash-register.operations', 'cash-register.receipt', 'cash-register.x-report', 'cash-register.z-report'],
        'cash.create' => ['cash-register.shifts.open', 'cash-register.sales.store', 'cash-register.balance-top-ups.store', 'cash-register.transactions.store', 'cash-register.shifts.close', 'cash-register.z-report.printed', 'club-computers.guest-session'],
        'cash.delete' => ['cash-register.refund'],
        'customers.view' => ['users.index'],
        'customers.create' => ['users.store'],
        'customers.update' => ['users.update', 'users.password'],
        'reservations.view' => ['reservations.index', 'reservations.customers'],
        'reservations.create' => ['reservations.store', 'reservations.store-many'],
        'reservations.update' => ['reservations.arrive'],
        'reservations.delete' => ['reservations.cancel'],
        'club_map.view' => ['club-map.index', 'club-computers.index'],
        'club_map.create' => ['club-map.store', 'club-computers.store'],
        'club_map.update' => ['club-map.update', 'club-map.layout', 'club-map.align', 'club-computers.update', 'club-computers.layout'],
        'club_map.delete' => ['club-map.destroy', 'club-computers.destroy'],
        'pc_control.update' => ['club-computers.control'],
        'tariffs.view' => ['tariffs.index', 'tariffs.edit'],
        'tariffs.create' => ['tariffs.store'],
        'tariffs.update' => ['tariffs.update'],
        'tariffs.delete' => ['tariffs.destroy'],
        'warehouse.view' => ['warehouse'],
        'warehouse.create' => ['warehouse.products.store', 'warehouse.categories.store'],
        'warehouse.update' => ['warehouse.products.update', 'warehouse.stock-movements.store'],
        'stock.view' => ['stock.index'],
        'stock.create' => ['stock.receive'],
        'orders.view' => ['shop-orders.index', 'shop-orders.state'],
        'orders.update' => ['shop-orders.done'],
        'orders.delete' => ['shop-orders.cancel'],
        'chat.view' => ['chat.index', 'chat.poll'],
        'chat.create' => ['chat.reply'],
        'todos.view' => ['todos.index', 'todos.state'],
        'todos.create' => ['todos.store'],
        'todos.update' => ['todos.toggle'],
        'todos.delete' => ['todos.destroy'],
        'news.view' => ['news.index'],
        'news.create' => ['news.store'],
        'news.update' => ['news.update'],
        'news.delete' => ['news.destroy'],
        'activity_logs.view' => ['activity-logs.index'],
        'settings.view' => ['settings', 'settings.club', 'settings.languages', 'settings.finances', 'settings.guests', 'settings.api'],
        'settings.update' => ['settings.save', 'settings.language', 'settings.timezone', 'settings.logo', 'settings.logo.destroy', 'settings.signin-image', 'settings.signin-image.destroy'],
        'workers.view' => ['workers.index'],
        'workers.create' => ['workers.store', 'club-invitations.store'],
        'workers.update' => ['workers.update', 'settings.agent-pin', 'settings.agent-staff-time'],
        'workers.delete' => ['workers.destroy'],
        'billing.view' => ['billing.show'],
    ];

    public static function rightFor(?string $routeName): ?string
    {
        foreach (self::ROUTES as $right => $names) {
            if (in_array($routeName, $names, true)) {
                return $right;
            }
        }

        return null;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $right = self::rightFor($request->route()?->getName());
        $membership = app(CurrentClub::class)->membership() ?? $request->user()->currentMembership()->first();

        abort_unless($right !== null && ClubPermissions::allows($membership, $right), 403, __('Нет права на это действие. Его даёт владелец клуба в «Настройки → Права».'));

        return $next($request);
    }
}
