<?php

namespace App\Http\Middleware;

use App\Services\CurrentClub;
use App\Support\ClubPermissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A right of the staff member in the current club (see ClubPermissions). */
class RequireClubPermission
{
    public function handle(Request $request, Closure $next, string $right): Response
    {
        $membership = app(CurrentClub::class)->membership() ?? $request->user()->currentMembership()->first();

        abort_unless(ClubPermissions::allows($membership, $right), 403, __('Нет права на этот раздел. Его даёт владелец клуба в «Настройки → Права».'));

        return $next($request);
    }
}
