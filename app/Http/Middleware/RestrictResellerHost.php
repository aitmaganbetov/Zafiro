<?php

namespace App\Http\Middleware;

use App\Support\ResellerHost;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The reseller host serves the reseller cabinet, sign-in and password recovery; the club sign-up
 * there is the reseller sign-up, and any other page goes to the cabinet.
 */
class RestrictResellerHost
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! ResellerHost::is($request) || $request->routeIs('reseller.*', 'auth.show-login', 'auth.login', 'auth.logout', 'auth.password.*')) {
            return $next($request);
        }

        return redirect()->route($request->routeIs('auth.show-register', 'auth.register') ? 'reseller.register' : 'reseller.dashboard');
    }
}
