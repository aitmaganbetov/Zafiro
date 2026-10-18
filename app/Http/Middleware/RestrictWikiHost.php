<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The wiki host (config app.wiki_domain) serves only the wiki: any other page goes to it. */
class RestrictWikiHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $domain = config('app.wiki_domain');
        if ($domain === null || strcasecmp($request->getHost(), $domain) !== 0 || $request->routeIs('wiki')) {
            return $next($request);
        }

        return redirect()->route('wiki');
    }
}
