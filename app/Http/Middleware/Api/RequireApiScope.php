<?php

namespace App\Http\Middleware\Api;

use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage: api.scope:products:read (every listed scope is required) or api.scope:any
 * for endpoints any valid key may call. AuthenticateApiKey rejects routes that do
 * not declare this middleware, so nothing is allowed by default.
 */
class RequireApiScope
{
    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        if ($scopes === ['any']) {
            return $next($request);
        }

        $granted = $request->attributes->get(AuthenticateApiKey::ATTRIBUTE)['scopes'] ?? [];
        $missing = array_values(array_diff($scopes, $granted));

        if ($scopes === [] || $missing !== []) {
            return ApiError::response('insufficient_scope', 403, extra: ['required_scopes' => $scopes === [] ? ['(none declared)'] : $missing]);
        }

        return $next($request);
    }
}
