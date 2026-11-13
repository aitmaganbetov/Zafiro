<?php

namespace App\Support;

use Illuminate\Http\Request;

/** The reseller cabinet lives on its own host (config app.reseller_domain), like the superadmin's. */
class ResellerHost
{
    public static function domain(): ?string
    {
        return config('app.reseller_domain');
    }

    public static function is(Request $request): bool
    {
        $domain = self::domain();

        return $domain !== null && strcasecmp($request->getHost(), $domain) === 0;
    }
}
