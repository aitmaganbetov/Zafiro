<?php

use App\Jobs\DeliverWebhook;
use App\Models\Club;
use App\Models\ClubComputer;
use App\Models\ClubZone;
use App\Models\ProductCategory;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\CurrentClub;
use App\Services\Integrations\WebhookUrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Public hosts resolve to a public address; the internal one to a private address.
    app()->instance(WebhookUrlGuard::class, new class extends WebhookUrlGuard
    {
        protected function resolve(string $host): array
        {
            return $host === 'internal.example.com' ? ['10.0.0.5'] : ['93.184.216.34'];
        }
    });
    Http::preventStrayRequests();
});

function webhookEndpoint(Club $club, User $owner, array $events, string $url = 'https://hooks.example.com/zafiro'): WebhookEndpoint
{
    $current = app(CurrentClub::class);
    $previous = $current->get();
    $current->set($club);

    try {
        $endpoint = (new WebhookEndpoint(['url' => $url, 'events' => $events, 'is_active' => true]))
            ->forceFill(['secret' => 'whsec_test_secret', 'created_by' => $owner->id]);
        $endpoint->save();

        return $endpoint;
    } finally {
        $previous === null ? $current->clear() : $current->set($previous);
    }
}

function clubCategory(Club $club): ProductCategory
{
    $current = app(CurrentClub::class);
    $previous = $current->get();
    $current->set($club);

    try {
        return ProductCategory::query()->create(['name' => 'Drinks']);
    } finally {
        $previous === null ? $current->clear() : $current->set($previous);
    }
}

test('events are delivered with a verifiable signature, timestamp and delivery id', function () {
    Http::fake(['hooks.example.com/*' => Http::response('', 204)]);
    [$club, $owner] = integrationClub();
    webhookEndpoint($club, $owner, ['product.created', 'stock.changed']);
    [, $plain] = issueApiKey($club, $owner, ['products:write']);

    $this->postJson('/api/v1/products', ['sku' => 'W-1', 'name' => 'Hook cola', 'product_category_id' => clubCategory($club)->id, 'price' => 100, 'quantity' => 3], ['Authorization' => "Bearer {$plain}"])
        ->assertCreated();

    Http::assertSentCount(2);
    Http::assertSent(function (HttpRequest $request) use ($club) {
        $timestamp = $request->header('X-Zafiro-Timestamp')[0];
        $expected = 'v1='.hash_hmac('sha256', $timestamp.'.'.$request->body(), 'whsec_test_secret');
        $payload = json_decode($request->body(), true);

        return hash_equals($expected, $request->header('X-Zafiro-Signature')[0])
            && abs(time() - (int) $timestamp) < 60
            && $request->header('X-Zafiro-Delivery')[0] === $payload['id']
            && $payload['club_id'] === $club->id
            && in_array($payload['event'], ['product.created', 'stock.changed'], true);
    });
    expect(WebhookDelivery::query()->withoutGlobalScope('club')->pluck('status')->unique()->all())->toBe(['delivered']);
});

test('only subscribed endpoints of the same club are called', function () {
    Http::fake(['*' => Http::response('', 200)]);
    [$club, $owner] = integrationClub();
    [$otherClub, $otherOwner] = integrationClub('other');
    webhookEndpoint($club, $owner, ['sale.completed'], 'https://hooks.example.com/sales-only');
    webhookEndpoint($otherClub, $otherOwner, ['product.created'], 'https://other.example.com/hook');
    [, $plain] = issueApiKey($club, $owner, ['products:write']);

    $this->postJson('/api/v1/products', ['sku' => 'W-2', 'name' => 'Quiet', 'product_category_id' => clubCategory($club)->id, 'price' => 1, 'quantity' => 0], ['Authorization' => "Bearer {$plain}"])
        ->assertCreated();

    Http::assertNothingSent();
    expect(WebhookDelivery::query()->withoutGlobalScope('club')->count())->toBe(0);
});

test('a rolled back operation sends nothing', function () {
    Http::fake(['*' => Http::response('', 200)]);
    [$club, $owner] = integrationClub();
    webhookEndpoint($club, $owner, ['stock.changed']);
    [, $plain] = issueApiKey($club, $owner, ['products:write', 'stock:write']);
    $product = $this->postJson('/api/v1/products', ['sku' => 'W-3', 'name' => 'Few', 'product_category_id' => clubCategory($club)->id, 'price' => 1, 'quantity' => 0], ['Authorization' => "Bearer {$plain}"])->json('data');

    $this->postJson('/api/v1/stock-movements', ['product_id' => $product['id'], 'type' => 'write_off', 'quantity' => 5], ['Authorization' => "Bearer {$plain}", 'Idempotency-Key' => 'r1'])
        ->assertUnprocessable();

    Http::assertNothingSent();
    expect(WebhookDelivery::query()->withoutGlobalScope('club')->count())->toBe(0);
});

test('failed deliveries are retried with backoff, then marked failed and the endpoint disabled', function () {
    config(['integrations.webhook_retry_backoff_seconds' => [10, 60], 'integrations.webhook_disable_after_failures' => 1]);
    Http::fake(['*' => Http::response('down', 503)]);
    [$club, $owner] = integrationClub();
    $endpoint = webhookEndpoint($club, $owner, ['stock.changed']);
    [, $plain] = issueApiKey($club, $owner, ['products:write']);

    $this->postJson('/api/v1/products', ['sku' => 'W-4', 'name' => 'Retry', 'product_category_id' => clubCategory($club)->id, 'price' => 1, 'quantity' => 2], ['Authorization' => "Bearer {$plain}"])->assertCreated();
    $delivery = WebhookDelivery::query()->withoutGlobalScope('club')->sole();

    expect($delivery->status)->toBe('pending')
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->last_status_code)->toBe(503)
        ->and($delivery->next_attempt_at->diffInSeconds(now(), true))->toBeGreaterThan(5);

    app()->call([new DeliverWebhook($delivery->id), 'handle']);
    app()->call([new DeliverWebhook($delivery->id), 'handle']);

    expect($delivery->refresh()->status)->toBe('failed')
        ->and($delivery->attempts)->toBe(3)
        ->and($endpoint->refresh()->is_active)->toBeFalse();

    // A disabled endpoint receives nothing new.
    $this->postJson('/api/v1/products', ['sku' => 'W-5', 'name' => 'After', 'product_category_id' => ProductCategory::query()->withoutGlobalScope('club')->where('club_id', $club->id)->value('id'), 'price' => 1, 'quantity' => 2], ['Authorization' => "Bearer {$plain}"])->assertCreated();
    expect(WebhookDelivery::query()->withoutGlobalScope('club')->count())->toBe(1);
    Http::assertSentCount(3);
});

test('webhooks to private networks, plain http and odd ports are blocked', function (string $url, string $reason) {
    Http::fake(['*' => Http::response('', 200)]);
    [$club, $owner] = integrationClub();
    webhookEndpoint($club, $owner, ['stock.changed'], $url);
    [, $plain] = issueApiKey($club, $owner, ['products:write']);
    config(['integrations.webhook_retry_backoff_seconds' => []]);

    $this->postJson('/api/v1/products', ['sku' => 'W-6', 'name' => 'Ssrf', 'product_category_id' => clubCategory($club)->id, 'price' => 1, 'quantity' => 1], ['Authorization' => "Bearer {$plain}"])->assertCreated();

    Http::assertNothingSent();
    expect(WebhookDelivery::query()->withoutGlobalScope('club')->sole()->only(['status', 'last_error']))
        ->toBe(['status' => 'failed', 'last_error' => "blocked: {$reason}"]);
})->with([
    'loopback' => ['https://127.0.0.1/hook', 'private_address'],
    'metadata service' => ['https://169.254.169.254/latest', 'private_address'],
    'private DNS' => ['https://internal.example.com/hook', 'private_address'],
    'plain http' => ['http://hooks.example.com/hook', 'https_required'],
    'odd port' => ['https://hooks.example.com:22/hook', 'port_not_allowed'],
    'credentials' => ['https://user:pass@hooks.example.com/hook', 'invalid_url'],
]);

test('sales and PC commands from the web interface are published too', function () {
    Http::fake(['*' => Http::response('', 200)]);
    [$club, $owner] = legacyClubOwner();
    webhookEndpoint($club, $owner, ['computer.controlled', 'computer.reservation_changed']);
    $zone = ClubZone::query()->create(['name' => 'Hall']);
    $computer = ClubComputer::query()->create(['club_zone_id' => $zone->id, 'number' => 3]);

    $this->actingAs($owner)->postJson(route('club-computers.control'), ['computer_ids' => [$computer->id], 'action' => 'restart'])->assertOk();
    $this->actingAs($owner)->postJson(route('club-computers.control'), ['computer_ids' => [$computer->id], 'action' => 'reserve'])->assertOk();

    expect(WebhookDelivery::query()->withoutGlobalScope('club')->orderBy('id')->pluck('event')->all())
        ->toBe(['computer.controlled', 'computer.reservation_changed']);
});

test('pruning removes only expired integration records', function () {
    [$club, $owner] = integrationClub();
    [$key] = issueApiKey($club, $owner);
    $endpoint = webhookEndpoint($club, $owner, ['stock.changed']);
    $log = fn ($at) => DB::table('api_request_logs')->insert(['club_id' => $club->id, 'method' => 'GET', 'endpoint' => '/api/v1/me', 'status' => 200, 'duration_ms' => 1, 'ip' => '1.1.1.1', 'created_at' => $at]);
    $log(now()->subDays(91));
    $log(now()->subDays(1));
    DB::table('api_idempotency_keys')->insert([
        ['api_key_id' => $key->id, 'idempotency_key' => 'old', 'request_hash' => str_repeat('a', 64), 'created_at' => now()->subHours(25)],
        ['api_key_id' => $key->id, 'idempotency_key' => 'new', 'request_hash' => str_repeat('a', 64), 'created_at' => now()],
    ]);
    foreach ([['delivered', 31], ['pending', 31], ['failed', 2]] as $i => [$status, $days]) {
        (new WebhookDelivery)->forceFill(['club_id' => $club->id, 'webhook_endpoint_id' => $endpoint->id, 'event_id' => (string) Str::uuid(), 'event' => 'stock.changed', 'payload' => [], 'status' => $status, 'created_at' => now()->subDays($days)])->save();
    }

    config(['cache.default' => 'database']);
    DB::table('cache')->insert([
        ['key' => 'old-bucket', 'value' => 'i:1;', 'expiration' => now()->subMinute()->getTimestamp()],
        ['key' => 'live-bucket', 'value' => 'i:1;', 'expiration' => now()->addMinute()->getTimestamp()],
    ]);

    $this->artisan('integrations:prune')->assertSuccessful();
    expect(DB::table('cache')->pluck('key')->all())->toBe(['live-bucket']);

    expect(DB::table('api_request_logs')->count())->toBe(1)
        ->and(DB::table('api_idempotency_keys')->pluck('idempotency_key')->all())->toBe(['new'])
        ->and(WebhookDelivery::query()->withoutGlobalScope('club')->orderBy('id')->pluck('status')->all())->toBe(['pending', 'failed']);
});
