<?php

namespace App\Http\Middleware;

use App\Support\ResellerHost;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The reseller host serves the reseller cabinet, sign-in and password recovery; any other page
 * goes to the cabinet (resellers do not sign up themselves: the superadmin creates them).
 */
class RestrictResellerHost
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! ResellerHost::is($request) || $request->routeIs('reseller.*', 'auth.show-login', 'auth.login', 'auth.logout', 'auth.password.*')) {
            return $next($request);
        }

        return redirect()->route('reseller.dashboard');
    }
}
