<?php

namespace App\Http\Middleware\Api;

use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Rejects request bodies larger than integrations.max_request_kb before they are parsed. */
class LimitApiRequestSize
{
    public function handle(Request $request, Closure $next): Response
    {
        $max = (int) config('integrations.max_request_kb', 256) * 1024;
        $declared = (int) $request->header('Content-Length', '0');

        if ($declared > $max || strlen((string) $request->getContent()) > $max) {
            return ApiError::response('payload_too_large', 413);
        }

        return $next($request);
    }
}
