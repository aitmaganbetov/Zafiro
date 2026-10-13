<?php

namespace App\Services\Integrations;

use App\Models\ApiKey;
use App\Models\Club;
use App\Models\ClubMembership;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Issues and verifies club API keys.
 *
 * Key format: zfk_<8-char prefix>_<40-char secret>. Only SHA-256 of the full key is
 * stored. Verification looks the key up by its unique prefix (one indexed query on a
 * cache miss) and compares hashes in constant time. Verified metadata is cached for
 * a short TTL under the prefix; the cache never contains the secret and is dropped
 * immediately when a key is revoked, rotated or its owner loses access.
 */
class ApiKeyManager
{
    private const TOKEN_PATTERN = '/^zfk_([a-z0-9]{8})_([A-Za-z0-9]{40})$/';

    /**
     * @param  array{name: string, scopes: list<string>, allowed_ips?: ?list<string>, rate_limit_per_minute?: ?int, expires_at?: ?Carbon}  $data
     * @return array{0: ApiKey, 1: string} the key and its plain-text secret (shown once)
     */
    public function create(User $creator, array $data, ?ApiKey $rotatedFrom = null): array
    {
        do {
            $prefix = 'zfk_'.Str::lower(Str::random(8));
        } while (ApiKey::query()->withoutGlobalScope('club')->where('prefix', $prefix)->exists());

        $plain = $prefix.'_'.Str::random(40);
        $key = new ApiKey([
            'name' => $data['name'],
            'scopes' => array_values(array_unique($data['scopes'])),
            'allowed_ips' => $data['allowed_ips'] ?? null ?: null,
            'rate_limit_per_minute' => $data['rate_limit_per_minute'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
        ]);
        $key->forceFill([
            // a rotated key always stays in the club of the key it replaces
            'club_id' => $rotatedFrom?->club_id,
            'prefix' => $prefix,
            'key_hash' => hash('sha256', $plain),
            'created_by' => $creator->getKey(),
            'rotated_from_id' => $rotatedFrom?->getKey(),
        ])->save();
        $this->forget($prefix); // drop a negative cache entry left by someone probing this prefix

        return [$key, $plain];
    }

    /** @return array{0: ApiKey, 1: string} */
    public function rotate(ApiKey $key, User $actor): array
    {
        return DB::transaction(function () use ($key, $actor): array {
            $result = $this->create($actor, [
                'name' => $key->name,
                'scopes' => $key->scopes,
                'allowed_ips' => $key->allowed_ips,
                'rate_limit_per_minute' => $key->rate_limit_per_minute,
                'expires_at' => $key->expires_at,
            ], $key);
            $this->revoke($key, $actor);

            return $result;
        });
    }

    public function revoke(ApiKey $key, User $actor): void
    {
        if ($key->revoked_at === null) {
            $key->forceFill(['revoked_at' => now(), 'revoked_by' => $actor->getKey()])->save();
        }

        $this->forget($key->prefix);
    }

    /** Drop cached metadata of every key a user created in a club (e.g. their access changed). */
    public function forgetKeysOf(int $userId, int $clubId): void
    {
        ApiKey::query()->withoutGlobalScope('club')
            ->where('club_id', $clubId)->where('created_by', $userId)
            ->pluck('prefix')
            ->each(fn (string $prefix) => $this->forget($prefix));
    }

    /** Drop cached metadata of every key of a club (subscription or club settings changed). */
    public function forgetClub(int $clubId): void
    {
        ApiKey::query()->withoutGlobalScope('club')
            ->where('club_id', $clubId)
            ->pluck('prefix')
            ->each(fn (string $prefix) => $this->forget($prefix));
    }

    /** A club model built from cached metadata, so authenticated requests need no club query. */
    public function clubFrom(array $meta): Club
    {
        return (new Club)->newFromBuilder($meta['club']);
    }

    public function forget(string $prefix): void
    {
        Cache::forget($this->cacheKey($prefix));
    }

    /**
     * Verified metadata for a presented key, or a failure reason.
     *
     * @return array{ok: bool, reason?: string, key?: array<string, mixed>}
     */
    public function verify(string $token): array
    {
        if (preg_match(self::TOKEN_PATTERN, $token, $m) !== 1) {
            return ['ok' => false, 'reason' => 'invalid_api_key'];
        }

        $prefix = 'zfk_'.$m[1];
        $meta = Cache::get($this->cacheKey($prefix));

        if ($meta === null) {
            $meta = $this->load($prefix);
            Cache::put($this->cacheKey($prefix), $meta, $meta === false ? 60 : (int) config('integrations.key_cache_ttl_seconds', 300));
        }

        if ($meta === false || ! hash_equals($meta['key_hash'], hash('sha256', $token))) {
            return ['ok' => false, 'reason' => 'invalid_api_key'];
        }

        return match (true) {
            $meta['revoked'] => ['ok' => false, 'reason' => 'api_key_revoked'],
            $meta['expires_at'] !== null && $meta['expires_at'] <= now()->getTimestamp() => ['ok' => false, 'reason' => 'api_key_expired'],
            $meta['club'] === null => ['ok' => false, 'reason' => 'invalid_api_key'],
            ! $meta['owner_has_access'] => ['ok' => false, 'reason' => 'api_key_owner_lost_access'],
            ! $meta['subscription_active'] => ['ok' => false, 'reason' => 'subscription_inactive'],
            default => ['ok' => true, 'key' => $meta],
        };
    }

    /** @return array<string, mixed>|false false when no key has this prefix */
    private function load(string $prefix): array|false
    {
        $key = ApiKey::query()->withoutGlobalScope('club')->where('prefix', $prefix)->first();

        if ($key === null) {
            return false;
        }

        $club = Club::query()->with('subscription')->find($key->club_id);
        $owner = ClubMembership::query()->withoutGlobalScope('club')
            ->where('club_id', $key->club_id)->where('user_id', $key->created_by)->first();

        return [
            'id' => $key->id,
            'prefix' => $key->prefix,
            'key_hash' => $key->key_hash,
            'club_id' => $key->club_id,
            // raw attributes without the encrypted installation key
            'club' => $club === null ? null : array_intersect_key($club->getAttributes(), array_flip(['id', 'name', 'slug', 'locale', 'settings', 'created_at', 'updated_at'])),
            'created_by' => $key->created_by,
            'scopes' => $key->scopes,
            'allowed_ips' => $key->allowed_ips ?: [],
            'rate_limit_per_minute' => $key->rate_limit_per_minute,
            'expires_at' => $key->expires_at?->getTimestamp(),
            'revoked' => $key->revoked_at !== null,
            'owner_has_access' => $owner?->canManageIntegrations() ?? false,
            'subscription_active' => $club?->subscription?->isActive() === true,
        ];
    }

    private function cacheKey(string $prefix): string
    {
        return "api_keys.meta.{$prefix}";
    }
}
