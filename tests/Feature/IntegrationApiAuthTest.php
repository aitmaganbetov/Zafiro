<?php

use App\Models\ApiKey;
use App\Models\ApiRequestLog;
use App\Models\ClubMembership;
use App\Models\ClubSubscription;
use App\Services\Integrations\ApiKeyManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Routes that exist only in these tests, behind the same middleware as /api/v1.
    Route::prefix('api/v1/test')
        ->middleware(['api', 'api.log', 'api.size', 'api.key', 'api.limit'])
        ->group(function () {
            Route::get('/write', fn () => ['ok' => true])->middleware('api.scope:products:read,products:write');
            Route::get('/undeclared', fn () => ['ok' => true]);
            Route::post('/charge', function () {
                $count = Cache::increment('test.charges');

                return response()->json(['charge' => $count], 201);
            })->middleware(['api.scope:products:read', 'api.idempotent:required']);
            Route::post('/echo', fn () => ['size' => strlen(request()->getContent())])->middleware('api.scope:products:read');
        });
});

function bearer(string $plain): array
{
    return ['Authorization' => "Bearer {$plain}"];
}

test('a valid key reaches its club and gets rate limit headers', function () {
    [$club, $owner] = integrationClub();
    [, $plain] = issueApiKey($club, $owner, ['products:read']);

    $this->getJson('/api/v1/me', bearer($plain))
        ->assertOk()
        ->assertJsonPath('data.club.id', $club->id)
        ->assertJsonPath('data.key.scopes', ['products:read'])
        ->assertHeader('X-RateLimit-Limit');

    $this->getJson('/api/v1/me', ['X-Api-Key' => $plain])->assertOk();
});

test('missing, malformed and wrong keys are rejected with the same error', function (array $headers) {
    [$club, $owner] = integrationClub();
    [, $plain] = issueApiKey($club, $owner);
    $headers = array_map(fn (string $value): string => str_replace('{key}', $plain, $value), $headers);

    $this->getJson('/api/v1/me', $headers)
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'invalid_api_key');
})->with([
    'no header' => [[]],
    'not a key' => [['Authorization' => 'Bearer hello']],
    'wrong secret' => [['Authorization' => 'Bearer {key}x']],
    'sanctum style token' => [['Authorization' => 'Bearer 1|abcdefghijklmnopqrstuvwxyz']],
]);

test('a wrong secret with a valid prefix is rejected', function () {
    [$club, $owner] = integrationClub();
    [, $plain] = issueApiKey($club, $owner);

    $this->getJson('/api/v1/me', bearer(substr($plain, 0, -1).(str_ends_with($plain, 'a') ? 'b' : 'a')))
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'invalid_api_key');
});

test('a revoked key stops working at once even when its metadata is cached', function () {
    [$club, $owner] = integrationClub();
    [$key, $plain] = issueApiKey($club, $owner);
    $this->getJson('/api/v1/me', bearer($plain))->assertOk();

    app(ApiKeyManager::class)->revoke($key, $owner);

    $this->getJson('/api/v1/me', bearer($plain))
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'api_key_revoked');
});

test('an expired key is rejected', function () {
    [$club, $owner] = integrationClub();
    [, $plain] = issueApiKey($club, $owner, data: ['expires_at' => now()->addHour()]);
    $this->getJson('/api/v1/me', bearer($plain))->assertOk();

    $this->travel(61)->minutes();

    $this->getJson('/api/v1/me', bearer($plain))
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'api_key_expired');
});

test('rotation revokes the old key and keeps the permissions on the new one', function () {
    [$club, $owner] = integrationClub();
    [$key, $old] = issueApiKey($club, $owner, ['products:read', 'sales:read']);

    [$rotated, $new] = app(ApiKeyManager::class)->rotate($key, $owner);

    expect($rotated->scopes)->toBe(['products:read', 'sales:read'])
        ->and($rotated->rotated_from_id)->toBe($key->id)
        ->and($new)->not->toBe($old);
    $this->getJson('/api/v1/me', bearer($old))->assertJsonPath('error.code', 'api_key_revoked');
    $this->getJson('/api/v1/me', bearer($new))->assertOk();
});

test('a key stops working when its creator loses the integrations permission', function () {
    [$club, $owner] = integrationClub();
    $membership = ClubMembership::query()->withoutGlobalScope('club')->where('club_id', $club->id)->where('user_id', $owner->id)->firstOrFail();
    $membership->forceFill(['role' => 'admin', 'can_manage_integrations' => true])->save();
    [, $plain] = issueApiKey($club, $owner);
    $this->getJson('/api/v1/me', bearer($plain))->assertOk();

    $membership->forceFill(['can_manage_integrations' => false])->save();

    $this->getJson('/api/v1/me', bearer($plain))->assertJsonPath('error.code', 'api_key_owner_lost_access');

    $membership->delete();
    $this->getJson('/api/v1/me', bearer($plain))->assertJsonPath('error.code', 'api_key_owner_lost_access');
});

test('keys of a club without an active subscription are refused', function () {
    [$club, $owner] = integrationClub();
    [, $plain] = issueApiKey($club, $owner);
    $this->getJson('/api/v1/me', bearer($plain))->assertOk();

    ClubSubscription::query()->where('club_id', $club->id)->firstOrFail()->update(['status' => 'canceled']);

    $this->getJson('/api/v1/me', bearer($plain))->assertStatus(402)->assertJsonPath('error.code', 'subscription_inactive');
});

test('the IP allowlist accepts listed addresses and networks only', function () {
    [$club, $owner] = integrationClub();
    [, $plain] = issueApiKey($club, $owner, data: ['allowed_ips' => ['10.0.0.0/24', '192.168.1.5']]);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.77'])->getJson('/api/v1/me', bearer($plain))->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.5'])->getJson('/api/v1/me', bearer($plain))->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.1'])->getJson('/api/v1/me', bearer($plain))
        ->assertForbidden()->assertJsonPath('error.code', 'ip_not_allowed');
});

test('the club cannot be chosen or spoofed through the request', function (string $where) {
    [$club, $owner] = integrationClub();
    [$otherClub] = integrationClub('victim-club');
    [, $plain] = issueApiKey($club, $owner);

    $request = match ($where) {
        'header' => fn (int $id) => $this->getJson('/api/v1/me', bearer($plain) + ['X-Club-Id' => (string) $id]),
        'query' => fn (int $id) => $this->getJson("/api/v1/me?club_id={$id}", bearer($plain)),
        'body' => fn (int $id) => $this->postJson('/api/v1/test/echo', ['club_id' => $id], bearer($plain)),
    };

    $request($otherClub->id)->assertForbidden()->assertJsonPath('error.code', 'tenant_override_forbidden');
    $request($club->id)->assertSuccessful();
})->with(['header', 'query', 'body']);

test('scopes are required and nothing is allowed by default', function () {
    [$club, $owner] = integrationClub();
    [, $plain] = issueApiKey($club, $owner, ['products:read']);
    [, $full] = issueApiKey($club, $owner, ['products:read', 'products:write']);

    $this->getJson('/api/v1/test/write', bearer($plain))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'insufficient_scope')
        ->assertJsonPath('error.required_scopes', ['products:write']);
    $this->getJson('/api/v1/test/write', bearer($full))->assertOk();
    $this->getJson('/api/v1/test/undeclared', bearer($full))->assertForbidden()->assertJsonPath('error.code', 'insufficient_scope');
});

test('every v1 route declares a scope', function () {
    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/') && ! str_starts_with($route->uri(), 'api/v1/test'));

    expect($routes)->not->toBeEmpty();
    $routes->each(fn ($route) => expect(collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'api.scope:')))->toBeTrue("{$route->uri()} has no api.scope"));
});

test('repeated failed authentication from one IP is blocked', function () {
    [$club, $owner] = integrationClub();
    [, $plain] = issueApiKey($club, $owner);
    config(['integrations.failed_auth_per_minute_per_ip' => 5]);

    foreach (range(1, 5) as $attempt) {
        $this->getJson('/api/v1/me', bearer('zfk_aaaaaaaa_'.str_repeat('b', 40)))->assertUnauthorized();
    }

    $this->getJson('/api/v1/me', bearer($plain))
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'too_many_failed_attempts')
        ->assertHeader('Retry-After');
    $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.9'])->getJson('/api/v1/me', bearer($plain))->assertOk();
});

test('revoked keys do not block the IP for other keys', function () {
    [$club, $owner] = integrationClub();
    [$key, $revoked] = issueApiKey($club, $owner);
    [, $plain] = issueApiKey($club, $owner);
    app(ApiKeyManager::class)->revoke($key, $owner);
    config(['integrations.failed_auth_per_minute_per_ip' => 3]);

    foreach (range(1, 6) as $attempt) {
        $this->getJson('/api/v1/me', bearer($revoked))->assertJsonPath('error.code', 'api_key_revoked');
    }

    $this->getJson('/api/v1/me', bearer($plain))->assertOk();
});

test('each key has its own rate limit', function () {
    [$club, $owner] = integrationClub();
    [, $slow] = issueApiKey($club, $owner, data: ['rate_limit_per_minute' => 3]);
    [, $other] = issueApiKey($club, $owner);

    foreach (range(1, 3) as $attempt) {
        $this->getJson('/api/v1/me', bearer($slow))->assertOk();
    }

    $this->getJson('/api/v1/me', bearer($slow))
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'rate_limited')
        ->assertHeader('Retry-After');
    $this->getJson('/api/v1/me', bearer($other))->assertOk();
});

test('all keys of a club share the club rate limit, other clubs are unaffected', function () {
    config(['integrations.club_rate_limit_per_minute' => 4]);
    [$club, $owner] = integrationClub();
    [$otherClub, $otherOwner] = integrationClub('second-club');
    [, $first] = issueApiKey($club, $owner);
    [, $second] = issueApiKey($club, $owner);
    [, $foreign] = issueApiKey($otherClub, $otherOwner);

    foreach (range(1, 2) as $attempt) {
        $this->getJson('/api/v1/me', bearer($first))->assertOk();
        $this->getJson('/api/v1/me', bearer($second))->assertOk();
    }

    $this->getJson('/api/v1/me', bearer($second))->assertStatus(429);
    $this->getJson('/api/v1/me', bearer($foreign))->assertOk();
});

test('oversized request bodies are refused', function () {
    [$club, $owner] = integrationClub();
    [, $plain] = issueApiKey($club, $owner);

    $this->postJson('/api/v1/test/echo', ['blob' => str_repeat('x', 300 * 1024)], bearer($plain))
        ->assertStatus(413)
        ->assertJsonPath('error.code', 'payload_too_large');
    $this->postJson('/api/v1/test/echo', ['blob' => 'small'], bearer($plain))->assertOk();
});

test('idempotency keys replay the first response and reject a different body', function () {
    [$club, $owner] = integrationClub();
    [, $plain] = issueApiKey($club, $owner);
    $headers = bearer($plain) + ['Idempotency-Key' => 'order-42'];

    $this->postJson('/api/v1/test/charge', ['amount' => 100], bearer($plain))
        ->assertStatus(428)->assertJsonPath('error.code', 'idempotency_key_required');

    $this->postJson('/api/v1/test/charge', ['amount' => 100], $headers)->assertCreated()->assertJsonPath('charge', 1);
    $this->postJson('/api/v1/test/charge', ['amount' => 100], $headers)
        ->assertCreated()
        ->assertJsonPath('charge', 1)
        ->assertHeader('Idempotent-Replayed', 'true');
    $this->postJson('/api/v1/test/charge', ['amount' => 999], $headers)
        ->assertStatus(422)->assertJsonPath('error.code', 'idempotency_key_reused');

    expect(Cache::get('test.charges'))->toBe(1);
});

test('idempotency keys of different API keys do not collide', function () {
    [$club, $owner] = integrationClub();
    [, $first] = issueApiKey($club, $owner);
    [, $second] = issueApiKey($club, $owner);

    $this->postJson('/api/v1/test/charge', [], bearer($first) + ['Idempotency-Key' => 'same'])->assertJsonPath('charge', 1);
    $this->postJson('/api/v1/test/charge', [], bearer($second) + ['Idempotency-Key' => 'same'])->assertJsonPath('charge', 2);
});

test('an idempotency key that is still being processed is not run twice', function () {
    [$club, $owner] = integrationClub();
    [$key, $plain] = issueApiKey($club, $owner);
    $body = json_encode([]);
    DB::table('api_idempotency_keys')->insert([
        'api_key_id' => $key->id,
        'idempotency_key' => 'busy',
        'request_hash' => hash('sha256', "POST api/v1/test/charge\n{$body}"),
        'created_at' => now(),
    ]);

    $this->postJson('/api/v1/test/charge', [], bearer($plain) + ['Idempotency-Key' => 'busy'])
        ->assertStatus(409)->assertJsonPath('error.code', 'idempotency_in_progress');
    expect(Cache::get('test.charges'))->toBeNull();
});

test('every call is audited without secrets or query strings', function () {
    [$club, $owner] = integrationClub();
    [$key, $plain] = issueApiKey($club, $owner);

    $this->getJson('/api/v1/me?token=secret-value', bearer($plain))->assertOk();
    $this->getJson('/api/v1/me', bearer(substr($plain, 0, -2).'zz'))->assertUnauthorized();

    $logs = ApiRequestLog::query()->orderBy('id')->get();
    expect($logs)->toHaveCount(2)
        ->and($logs[0]->only(['club_id', 'api_key_id', 'key_prefix', 'method', 'endpoint', 'status']))->toBe([
            'club_id' => $club->id, 'api_key_id' => $key->id, 'key_prefix' => $key->prefix, 'method' => 'GET', 'endpoint' => '/api/v1/me', 'status' => 200,
        ])
        ->and($logs[1]->club_id)->toBeNull()
        ->and($logs[1]->key_prefix)->toBe($key->prefix)
        ->and($logs[1]->status)->toBe(401)
        ->and($logs->toJson())->not->toContain(substr($plain, 13))->not->toContain('secret-value');
});

test('only a hash is stored and the cache never holds the secret', function () {
    [$club, $owner] = integrationClub();
    [$key, $plain] = issueApiKey($club, $owner);
    $this->getJson('/api/v1/me', bearer($plain))->assertOk();
    $secret = substr($plain, 13);

    $row = (array) DB::table('api_keys')->where('id', $key->id)->first();
    expect(json_encode($row))->not->toContain($secret)
        ->and($row['key_hash'])->toBe(hash('sha256', $plain))
        ->and(serialize(Cache::get("api_keys.meta.{$key->prefix}")))->not->toContain($secret)
        ->and($key->toArray())->not->toHaveKey('key_hash');
});

test('an authenticated call with warm caches costs one query: the audit row', function () {
    [$club, $owner] = integrationClub();
    [, $plain] = issueApiKey($club, $owner);
    $this->getJson('/api/v1/me', bearer($plain))->assertOk();

    DB::enableQueryLog();
    $this->getJson('/api/v1/me', bearer($plain))->assertOk();
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect($queries)->toHaveCount(1)
        ->and($queries[0])->toContain('insert into "api_request_logs"');
});

test('a cold call verifies the key with a few indexed queries and no key list', function () {
    [$club, $owner] = integrationClub();
    foreach (range(1, 5) as $i) {
        issueApiKey($club, $owner);
    }
    [, $plain] = issueApiKey($club, $owner);
    Cache::flush();

    DB::enableQueryLog();
    $this->getJson('/api/v1/me', bearer($plain))->assertOk();
    $keyQueries = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $q) => str_contains($q, 'from "api_keys"'));
    DB::disableQueryLog();

    expect($keyQueries)->toHaveCount(1)
        ->and($keyQueries->first())->toContain('"prefix" = ?');
});

test('unknown v1 endpoints answer with the JSON error envelope', function () {
    $this->getJson('/api/v1/nothing-here')->assertNotFound()->assertJsonPath('error.code', 'not_found');
});

test('existing API endpoints keep their behaviour', function () {
    $this->getJson('/api/products')->assertUnauthorized()->assertJsonMissingPath('error.code');
    expect(ApiKey::query()->withoutGlobalScope('club')->count())->toBe(0);
});
