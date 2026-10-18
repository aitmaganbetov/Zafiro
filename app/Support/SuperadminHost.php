<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The superadmin cabinet lives on its own host (config app.superadmin_domain), away from the
 * club panel: the club site has no cabinet routes, and the cabinet host serves only the cabinet,
 * sign-in and a club a superadmin is viewing.
 */
class SuperadminHost
{
    public static function domain(): ?string
    {
        return config('app.superadmin_domain');
    }

    public static function is(Request $request): bool
    {
        $domain = self::domain();

        return $domain !== null && strcasecmp($request->getHost(), $domain) === 0;
    }
}
