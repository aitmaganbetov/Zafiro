<?php

namespace App\Http\Middleware;

use App\Services\CurrentClub;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireClubRole
{
    public function handle(Request $request, Closure $next, string ...$allowedRoles): Response
    {
        $role = app(CurrentClub::class)->membership()?->role
            ?? $request->user()->currentMembership()->value('role');

        abort_if($role === null, 403);
        abort_unless($role === 'owner' || in_array($role, $allowedRoles, true), 403);

        return $next($request);
    }
}
