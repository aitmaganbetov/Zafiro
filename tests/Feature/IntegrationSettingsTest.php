<?php

use App\Models\ActivityLog;
use App\Models\ApiKey;
use App\Models\ClubMembership;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\CurrentClub;
use App\Services\Integrations\WebhookUrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()->instance(WebhookUrlGuard::class, new class extends WebhookUrlGuard
    {
        protected function resolve(string $host): array
        {
            return $host === 'internal.example.com' ? ['192.168.0.10'] : ['93.184.216.34'];
        }
    });
});

function staffMember(string $role, bool $integrations = false): User
{
    $user = User::factory()->create();
    ClubMembership::query()->where('user_id', $user->id)->update(['role' => $role, 'can_manage_integrations' => $integrations]);

    return $user;
}

test('the page is open to the owner and to staff with the integrations permission only', function () {
    [, $owner] = legacyClubOwner();
    $admin = staffMember('admin');
    $cashier = staffMember('cashier', true);

    $this->actingAs($owner)->get(route('settings.integrations'))->assertOk()->assertSee(__('API и интеграции'))->assertSee(__('Доступ к интеграциям'));
    $this->flushSession();
    $this->actingAs($admin)->get(route('settings.integrations'))->assertForbidden();
    $this->actingAs($admin)->get(route('settings.club'))->assertOk()->assertDontSee(route('settings.integrations'));
    $this->flushSession();
    $this->actingAs($cashier)->get(route('settings.integrations'))->assertOk()->assertDontSee(__('Доступ к интеграциям'));
});

test('a new key secret is shown once and only its hash is stored', function () {
    [, $owner] = legacyClubOwner();

    $response = $this->actingAs($owner)->post(route('settings.integrations.keys.store'), [
        'name' => 'Accounting',
        'scopes' => ['products:read', 'sales:read'],
        'allowed_ips' => "203.0.113.10\n198.51.100.0/24",
        'rate_limit_per_minute' => 60,
        'expires_at' => now()->addMonth()->toDateString(),
    ])->assertOk()->assertHeader('Cache-Control', 'no-store, private');

    preg_match('/zfk_[a-z0-9]{8}_[A-Za-z0-9]{40}/', $response->getContent(), $match);
    $key = ApiKey::query()->sole();

    expect($match)->not->toBeEmpty()
        ->and($key->key_hash)->toBe(hash('sha256', $match[0]))
        ->and($key->allowed_ips)->toBe(['203.0.113.10', '198.51.100.0/24'])
        ->and(json_encode(session()->all()))->not->toContain($match[0]);
    $this->get(route('settings.integrations'))->assertOk()->assertSee($key->prefix)->assertDontSee($match[0]);

    $log = ActivityLog::query()->where('event', 'api_key.created')->sole();
    expect($log->user_id)->toBe($owner->id)->and($log->ip_address)->toBe('127.0.0.1')
        ->and(json_encode($log->details))->not->toContain($match[0]);
});

test('key input is validated', function (array $input, string $field) {
    [, $owner] = legacyClubOwner();

    $this->actingAs($owner)->from(route('settings.integrations'))
        ->post(route('settings.integrations.keys.store'), [...['name' => 'K', 'scopes' => ['products:read']], ...$input])
        ->assertSessionHasErrors($field);
    expect(ApiKey::query()->count())->toBe(0);
})->with([
    'unknown scope' => [['scopes' => ['everything']], 'scopes.0'],
    'no scope' => [['scopes' => []], 'scopes'],
    'bad ip' => [['allowed_ips' => '10.0.0.300'], 'allowed_ips'],
    'bad network' => [['allowed_ips' => '10.0.0.0/40'], 'allowed_ips'],
    'past expiry' => [['expires_at' => now()->subDay()->toDateString()], 'expires_at'],
    'limit too high' => [['rate_limit_per_minute' => 100000], 'rate_limit_per_minute'],
]);

test('revoking and rotating keys from the page takes effect immediately', function () {
    [$club, $owner] = legacyClubOwner();
    [$key, $plain] = issueApiKey($club, $owner);
    $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$plain}"])->assertOk();
    app(CurrentClub::class)->set($club);

    $rotated = $this->actingAs($owner)->post(route('settings.integrations.keys.rotate', $key))->assertOk();
    preg_match('/zfk_[a-z0-9]{8}_[A-Za-z0-9]{40}/', $rotated->getContent(), $match);
    $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$plain}"])->assertUnauthorized();
    $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$match[0]}"])->assertOk();

    app(CurrentClub::class)->set($club);
    $new = ApiKey::query()->whereNull('revoked_at')->sole();
    $this->actingAs($owner)->delete(route('settings.integrations.keys.revoke', $new))->assertRedirect(route('settings.integrations'));
    $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$match[0]}"])->assertUnauthorized()->assertJsonPath('error.code', 'api_key_revoked');

    expect(ActivityLog::query()->withoutGlobalScope('club')->whereIn('event', ['api_key.rotated', 'api_key.revoked'])->count())->toBe(2);
});

test('keys and webhooks of another club cannot be touched', function () {
    [$club, $owner] = legacyClubOwner();
    [$otherClub, $otherOwner] = integrationClub();
    [$foreignKey] = issueApiKey($otherClub, $otherOwner);
    app(CurrentClub::class)->set($otherClub);
    $foreignHook = (new WebhookEndpoint(['url' => 'https://other.example.com', 'events' => ['stock.changed'], 'is_active' => true]))->forceFill(['secret' => 's', 'created_by' => $otherOwner->id]);
    $foreignHook->save();
    app(CurrentClub::class)->set($club);

    $this->actingAs($owner)->delete(route('settings.integrations.keys.revoke', $foreignKey->id))->assertNotFound();
    $this->actingAs($owner)->post(route('settings.integrations.keys.rotate', $foreignKey->id))->assertNotFound();
    $this->actingAs($owner)->delete(route('settings.integrations.webhooks.destroy', $foreignHook->id))->assertNotFound();
    $this->actingAs($owner)->get(route('settings.integrations'))->assertDontSee($foreignKey->prefix)->assertDontSee('other.example.com');

    expect($foreignKey->refresh()->revoked_at)->toBeNull()
        ->and(WebhookEndpoint::query()->withoutGlobalScope('club')->whereKey($foreignHook->id)->exists())->toBeTrue();
});

test('webhooks are created with a one-time secret and checked against internal addresses', function () {
    [, $owner] = legacyClubOwner();

    $this->actingAs($owner)->from(route('settings.integrations'))->post(route('settings.integrations.webhooks.store'), ['url' => 'https://internal.example.com/hook', 'events' => ['stock.changed']])
        ->assertSessionHasErrors('url');
    $this->actingAs($owner)->from(route('settings.integrations'))->post(route('settings.integrations.webhooks.store'), ['url' => 'http://hooks.example.com/hook', 'events' => ['stock.changed']])
        ->assertSessionHasErrors('url');

    $response = $this->actingAs($owner)->post(route('settings.integrations.webhooks.store'), ['url' => 'https://hooks.example.com/hook', 'events' => ['stock.changed', 'sale.completed']])->assertOk();
    $endpoint = WebhookEndpoint::query()->sole();

    expect($response->getContent())->toContain($endpoint->secret)
        ->and(DB::table('webhook_endpoints')->value('secret'))->not->toContain($endpoint->secret);
    $this->get(route('settings.integrations'))->assertDontSee($endpoint->secret);

    $this->patch(route('settings.integrations.webhooks.update', $endpoint), ['is_active' => 0])->assertRedirect();
    expect($endpoint->refresh()->is_active)->toBeFalse();

    $delivery = (new WebhookDelivery)->forceFill(['club_id' => $endpoint->club_id, 'webhook_endpoint_id' => $endpoint->id, 'event_id' => (string) Str::uuid(), 'event' => 'stock.changed', 'payload' => [], 'status' => 'failed', 'attempts' => 6]);
    $delivery->save();
    $this->post(route('settings.integrations.deliveries.retry', $delivery))->assertRedirect();
    expect($delivery->refresh()->status)->toBe('skipped'); // the endpoint is disabled, so the sync queue skipped it

    $this->delete(route('settings.integrations.webhooks.destroy', $endpoint))->assertRedirect();
    expect(WebhookEndpoint::query()->count())->toBe(0)
        ->and(ActivityLog::query()->whereIn('event', ['webhook.created', 'webhook.updated', 'webhook.retried', 'webhook.deleted'])->count())->toBe(4);
});

test('only the owner grants the integrations permission and removing it disables the member\'s keys', function () {
    [$club, $owner] = legacyClubOwner();
    $manager = staffMember('manager', true);
    $cashier = staffMember('cashier');
    $managerMembership = ClubMembership::query()->where('user_id', $manager->id)->sole();
    [, $plain] = issueApiKey($club, $manager);
    $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$plain}"])->assertOk();
    app(CurrentClub::class)->set($club);

    $this->actingAs($manager)->patch(route('settings.integrations.permissions.update', ClubMembership::query()->where('user_id', $cashier->id)->sole()), ['can_manage_integrations' => 1])->assertForbidden();
    $this->flushSession();
    $this->actingAs($owner)->patch(route('settings.integrations.permissions.update', $managerMembership), ['can_manage_integrations' => 0])->assertRedirect();

    expect($managerMembership->refresh()->can_manage_integrations)->toBeFalse()
        ->and(ActivityLog::query()->where('event', 'integrations.permission_changed')->sole()->user_id)->toBe($owner->id);
    $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$plain}"])->assertUnauthorized()->assertJsonPath('error.code', 'api_key_owner_lost_access');
});

test('the API reference is published and documents every v1 route', function () {
    $this->get(route('api-docs'))->assertOk()->assertSee('swagger-ui');
    $spec = $this->get(route('api-docs.spec'))->assertOk()->assertHeader('Content-Type', 'application/yaml; charset=utf-8');

    $documented = collect(Yaml::parse($spec->streamedContent() ?: file_get_contents(resource_path('api/openapi-v1.yaml')))['paths'])
        ->flatMap(fn (array $operations, string $path) => collect($operations)->keys()->filter(fn ($m) => $m !== 'parameters')->map(fn ($m) => strtoupper($m).' '.$path))
        ->sort()->values()->all();
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/'))
        ->flatMap(fn ($route) => collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->map(fn ($m) => $m.' '.substr($route->uri(), 6)))
        ->sort()->values()->all();

    expect($documented)->toBe($routes);
});
