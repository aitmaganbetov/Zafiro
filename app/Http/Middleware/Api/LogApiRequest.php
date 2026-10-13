<?php

namespace App\Http\Middleware\Api;

use App\Models\ApiRequestLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Audit trail of every /api/v1 call, including rejected ones: time, IP, key prefix,
 * method, path, status and duration. Query strings, headers and bodies are never
 * stored, so secrets and personal data cannot end up in the log.
 */
class LogApiRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $started = hrtime(true);
        $response = $next($request);
        $key = $request->attributes->get(AuthenticateApiKey::ATTRIBUTE);

        try {
            ApiRequestLog::query()->insert([
                'club_id' => $key['club_id'] ?? null,
                'api_key_id' => $key['id'] ?? null,
                'key_prefix' => $key['prefix'] ?? $request->attributes->get('api_key_prefix'),
                'method' => substr($request->method(), 0, 8),
                'endpoint' => mb_substr('/'.ltrim($request->path(), '/'), 0, 191),
                'status' => $response->getStatusCode(),
                'duration_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
                'ip' => (string) $request->ip(),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // A failing audit write must not turn a successful call into an error.
            Log::error('API request log write failed', ['exception' => $e::class]);
        }

        return $response;
    }
}
