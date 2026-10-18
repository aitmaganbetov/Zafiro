<?php

namespace App\Http\Middleware;

use App\Support\SuperadminHost;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The cabinet host serves the cabinet and sign-in; the club panel only while a superadmin is
 * viewing a club. Anything else there goes to the cabinet.
 */
class RestrictSuperadminHost
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! SuperadminHost::is($request) || $request->routeIs('superadmin.*', 'languages.*', 'auth.*')) {
            return $next($request);
        }

        if ($request->user()?->isSuperadmin() === true && $request->session()->has(SetCurrentClub::VIEWING_KEY)) {
            return $next($request);
        }

        return redirect()->route('superadmin.dashboard');
    }
}
