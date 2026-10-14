<?php

namespace App\Http\Middleware\Api;

use App\Models\ApiKey;
use App\Services\CurrentClub;
use App\Services\Integrations\ApiKeyManager;
use App\Services\LocaleResolver;
use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates /api/v1 requests by API key and makes the key's club the current
 * club. The club comes only from the key: a club id in the query, body or the
 * X-Club-Id header that names another club is rejected, and controllers never read one.
 */
class AuthenticateApiKey
{
    public const ATTRIBUTE = 'api_key';

    public function __construct(
        private ApiKeyManager $keys,
        private CurrentClub $currentClub,
        private LocaleResolver $locales,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $limiterKey = 'api-auth-failed:'.$request->ip();
        $maxFailures = (int) config('integrations.failed_auth_per_minute_per_ip', 20);

        if (RateLimiter::tooManyAttempts($limiterKey, $maxFailures)) {
            $retry = RateLimiter::availableIn($limiterKey);

            return ApiError::response('too_many_failed_attempts', 429, headers: ['Retry-After' => (string) $retry]);
        }

        $token = $this->presentedToken($request);
        $request->attributes->set('api_key_prefix', $this->prefixOf($token));
        $result = $token === null ? ['ok' => false, 'reason' => 'invalid_api_key'] : $this->keys->verify($token);

        if (! $result['ok']) {
            // Only guesses count towards the IP block. A revoked or expired key is a real
            // key with the right secret; blocking its IP would also lock out other keys
            // behind the same address.
            if ($result['reason'] === 'invalid_api_key') {
                RateLimiter::hit($limiterKey, 60);
            }

            return ApiError::response($result['reason'], $result['reason'] === 'subscription_inactive' ? 402 : 401, headers: ['WWW-Authenticate' => 'Bearer']);
        }

        $key = $result['key'];
        $request->attributes->set(self::ATTRIBUTE, $key);

        if ($key['allowed_ips'] !== [] && ! IpUtils::checkIp((string) $request->ip(), $key['allowed_ips'])) {
            return ApiError::response('ip_not_allowed', 403);
        }

        if (! $this->declaresScope($request)) {
            return ApiError::response('insufficient_scope', 403);
        }

        if ($this->overridesTenant($request, $key['club_id'])) {
            return ApiError::response('tenant_override_forbidden', 403);
        }

        $club = $this->keys->clubFrom($key);
        $this->currentClub->set($club);
        $this->currentClub->useUtc();
        $this->locales->apply($this->locales->forClub($club));
        $this->touch($key, (string) $request->ip());

        return $next($request);
    }

    private function presentedToken(Request $request): ?string
    {
        $token = $request->bearerToken() ?? $request->header('X-Api-Key');

        return is_string($token) && $token !== '' && strlen($token) <= 128 ? $token : null;
    }

    /** The non-secret key prefix for the request log, only when the token is well-formed. */
    private function prefixOf(?string $token): ?string
    {
        return $token !== null && preg_match('/^(zfk_[a-z0-9]{8})_[A-Za-z0-9]{40}$/', $token, $m) === 1 ? $m[1] : null;
    }

    /** Default deny: an endpoint without an api.scope middleware is not reachable with a key. */
    private function declaresScope(Request $request): bool
    {
        foreach ($request->route()?->gatherMiddleware() ?? [] as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'api.scope:')) {
                return true;
            }
        }

        return false;
    }

    private function overridesTenant(Request $request, int $clubId): bool
    {
        $claimed = array_filter([
            $request->header('X-Club-Id'),
            $request->query('club_id'),
            $request->isJson() ? $request->json('club_id') : $request->request->get('club_id'),
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        foreach ($claimed as $value) {
            if (! is_scalar($value) || (string) $value !== (string) $clubId) {
                return true;
            }
        }

        return false;
    }

    /** last_used_at is updated at most once a minute per key to keep hot keys cheap. */
    private function touch(array $key, string $ip): void
    {
        if (Cache::add("api_keys.touched.{$key['id']}", true, 60)) {
            ApiKey::query()->withoutGlobalScope('club')->whereKey($key['id'])->update(['last_used_at' => now(), 'last_used_ip' => $ip]);
        }
    }
}
