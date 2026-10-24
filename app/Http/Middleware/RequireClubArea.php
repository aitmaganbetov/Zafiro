<?php

namespace App\Http\Middleware;

use App\Services\CurrentClub;
use App\Support\ClubPermissions;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The right a panel page needs, by its route name (ClubPermissions). A route of a guarded group
 * that is not listed here is refused: a new page gets its right here first.
 */
class RequireClubArea
{
    /** route name pattern => right */
    public const AREAS = [
        'warehouse' => 'warehouse',
        'warehouse.*' => 'warehouse',
        'activity-logs.*' => 'activity_logs',
        'club-invitations.*' => 'workers',
        'workers.*' => 'workers',
        'settings.agent-pin' => 'workers',
        'settings.agent-staff-time' => 'workers',
        'club-map.store' => 'club_map_edit',
        'club-map.update' => 'club_map_edit',
        'club-map.destroy' => 'club_map_edit',
        'club-map.layout' => 'club_map_edit',
        'club-map.align' => 'club_map_edit',
        'club-computers.store' => 'club_map_edit',
        'club-computers.update' => 'club_map_edit',
        'club-computers.destroy' => 'club_map_edit',
        'club-computers.layout' => 'club_map_edit',
        'settings' => 'settings',
        'settings.*' => 'settings',
        'tariffs.*' => 'tariffs',
        'todos.store' => 'todos_manage',
        'todos.destroy' => 'todos_manage',
        'news.*' => 'news',
        'cash-register.*' => 'cash_register',
        'club-computers.guest-session' => 'cash_register',
    ];

    public static function rightFor(?string $routeName): ?string
    {
        foreach (self::AREAS as $pattern => $right) {
            if ($routeName !== null && Str::is($pattern, $routeName)) {
                return $right;
            }
        }

        return null;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $right = self::rightFor($request->route()?->getName());
        $membership = app(CurrentClub::class)->membership() ?? $request->user()->currentMembership()->first();

        abort_unless($right !== null && ClubPermissions::allows($membership, $right), 403, __('Нет права на этот раздел. Его даёт владелец клуба в «Настройки → Права».'));

        return $next($request);
    }
}
