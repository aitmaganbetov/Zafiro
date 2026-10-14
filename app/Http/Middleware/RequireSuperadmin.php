<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Platform superadmin cabinet: only users with the superadmin flag who are not blocked. */
class RequireSuperadmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user?->isSuperadmin() === true && ! $user->isBlocked(), 403);

        return $next($request);
    }
}
