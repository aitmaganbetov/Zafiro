<?php

namespace App\Http\Middleware\Api;

use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fixed one-minute windows per API key and per club. The counter is incremented
 * first and compared afterwards (one atomic increment per bucket), so parallel
 * requests cannot slip past the limit the way check-then-increment throttling can.
 */
class ApiRateLimit
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->attributes->get(AuthenticateApiKey::ATTRIBUTE);
        $window = intdiv(now()->getTimestamp(), 60);
        $retryAfter = 60 - now()->getTimestamp() % 60;
        $keyLimit = min((int) ($key['rate_limit_per_minute'] ?? config('integrations.default_rate_limit_per_minute')), (int) config('integrations.max_rate_limit_per_minute'));
        $clubLimit = (int) config('integrations.club_rate_limit_per_minute');

        $keyHits = $this->hit("api-rl.key.{$key['id']}.{$window}");
        $clubHits = $this->hit("api-rl.club.{$key['club_id']}.{$window}");
        $headers = ['X-RateLimit-Limit' => (string) $keyLimit, 'X-RateLimit-Remaining' => (string) max(0, $keyLimit - $keyHits)];

        if ($keyHits > $keyLimit || $clubHits > $clubLimit) {
            return ApiError::response('rate_limited', 429, headers: [...$headers, 'X-RateLimit-Remaining' => '0', 'Retry-After' => (string) $retryAfter]);
        }

        $response = $next($request);
        $response->headers->add($headers);

        return $response;
    }

    /** One atomic increment on the hot path; the bucket is created once per window. */
    private function hit(string $bucket): int
    {
        $hits = Cache::increment($bucket);

        if (! $hits) {
            // Two requests may open the window at once: only one add() wins.
            $hits = Cache::add($bucket, 1, 120) ? 1 : (int) Cache::increment($bucket);
        }

        return (int) $hits;
    }
}
