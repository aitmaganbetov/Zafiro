<?php

use App\Models\ActivityLog;
use App\Models\CashShift;
use App\Models\CashTransaction;
use App\Models\Club;
use App\Models\ClubComputer;
use App\Models\ClubMembership;
use App\Models\ClubZone;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\StockMovement;
use App\Models\Tariff;
use App\Models\User;
use App\Services\CurrentClub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const ALL_SCOPES = ['products:read', 'products:write', 'stock:write', 'sales:read', 'computers:read', 'bookings:write', 'computers:control', 'tariffs:read', 'members:read'];

/**
 * One of everything in $club, named after $tag so leaks are easy to spot.
 *
 * @return array<string, int>
 */
function seedIntegrationData(Club $club, User $owner, string $tag): array
{
    $current = app(CurrentClub::class);
    $previous = $current->get();
    $current->set($club);

    try {
        $category = ProductCategory::query()->create(['name' => "{$tag} drinks"]);
        $product = Product::query()->create(['sku' => 'SKU-SAME', 'name' => "{$tag} cola", 'category' => $category->name, 'product_category_id' => $category->id, 'price' => 500, 'quantity' => 10]);
        $movement = StockMovement::query()->create(['product_id' => $product->id, 'user_id' => $owner->id, 'type' => 'receipt', 'quantity' => 10, 'balance_after' => 10]);
        $zone = ClubZone::query()->create(['name' => "{$tag} hall"]);
        $computer = ClubComputer::query()->create(['club_zone_id' => $zone->id, 'number' => 1, 'device_name' => "{$tag}-PC"]);
        $tariff = Tariff::query()->create(['name' => "{$tag} hour", 'duration_minutes' => 60, 'price' => 1000, 'is_active' => true]);
        $tariff->zones()->attach($zone->id, ['club_id' => $club->id]);
        $shift = CashShift::query()->create(['opened_by' => $owner->id, 'status' => CashShift::STATUS_OPEN, 'open_marker' => true, 'opening_balance' => 0, 'opened_at' => now()]);
        $sale = $shift->transactions()->create(['user_id' => $owner->id, 'type' => CashTransaction::TYPE_INCOME, 'amount' => 500, 'description' => 'Продажа POS', 'receipt_data' => ['items' => [['name' => "{$tag} cola", 'quantity' => 1, 'price' => 500, 'total' => 500]]]]);
        $shift->transactions()->create(['user_id' => $owner->id, 'type' => CashTransaction::TYPE_INCOME, 'amount' => 100, 'description' => 'Cash in']);
    } finally {
        $previous === null ? $current->clear() : $current->set($previous);
    }

    return ['category' => $category->id, 'product' => $product->id, 'movement' => $movement->id, 'zone' => $zone->id, 'computer' => $computer->id, 'tariff' => $tariff->id, 'sale' => $sale->id];
}

/** @return array{0: Club, 1: array<string, int>, 2: Club, 3: array<string, int>, 4: string} */
function twoClubsWithData(array $scopes = ALL_SCOPES): array
{
    [$mine, $owner] = integrationClub('mine');
    [$theirs, $theirOwner] = integrationClub('theirs');
    $myData = seedIntegrationData($mine, $owner, 'Mine');
    $theirData = seedIntegrationData($theirs, $theirOwner, 'Theirs');
    [, $plain] = issueApiKey($mine, $owner, $scopes);

    return [$mine, $myData, $theirs, $theirData, $plain];
}

function withKey(string $plain, array $extra = []): array
{
    return ['Authorization' => "Bearer {$plain}", ...$extra];
}

test('every list endpoint returns only the key\'s club', function (string $endpoint) {
    [, , , , $plain] = twoClubsWithData();

    $response = $this->getJson($endpoint, withKey($plain))->assertOk();

    expect($response->json('data'))->not->toBeEmpty()
        ->and($response->getContent())->not->toContain('Theirs');
})->with(['/api/v1/products', '/api/v1/product-categories', '/api/v1/stock-movements', '/api/v1/sales', '/api/v1/zones', '/api/v1/computers', '/api/v1/tariffs', '/api/v1/members']);

test('lists contain the club\'s own records', function () {
    [, $mine, , , $plain] = twoClubsWithData();

    $this->getJson('/api/v1/products', withKey($plain))->assertJsonPath('data.0.id', $mine['product'])->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/sales', withKey($plain))->assertJsonPath('data.0.id', $mine['sale'])->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/tariffs', withKey($plain))->assertJsonPath('data.0.zone_ids', [$mine['zone']]);
    $this->getJson('/api/v1/zones', withKey($plain))->assertJsonPath('data.0.computers_count', 1);
});

test('records of another club cannot be read or changed by id', function () {
    [, , , $theirs, $plain] = twoClubsWithData();
    $idempotent = withKey($plain, ['Idempotency-Key' => 'k1']);

    $this->getJson("/api/v1/products/{$theirs['product']}", withKey($plain))->assertNotFound()->assertJsonPath('error.code', 'not_found');
    $this->patchJson("/api/v1/products/{$theirs['product']}", ['price' => 1], withKey($plain))->assertNotFound();
    $this->getJson("/api/v1/sales/{$theirs['sale']}", withKey($plain))->assertNotFound();
    $this->getJson("/api/v1/computers/{$theirs['computer']}", withKey($plain))->assertNotFound();
    $this->putJson("/api/v1/computers/{$theirs['computer']}/reservation", ['reserved' => true], withKey($plain))->assertNotFound();
    $this->postJson('/api/v1/computers/control', ['computer_ids' => [$theirs['computer']], 'action' => 'power_off'], $idempotent)
        ->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
    $this->postJson('/api/v1/stock-movements', ['product_id' => $theirs['product'], 'type' => 'write_off', 'quantity' => 1], withKey($plain, ['Idempotency-Key' => 'k2']))
        ->assertUnprocessable();
    $this->postJson('/api/v1/products', ['sku' => 'NEW', 'name' => 'X', 'product_category_id' => $theirs['category'], 'price' => 1, 'quantity' => 0], withKey($plain))
        ->assertUnprocessable()->assertJsonValidationErrors('product_category_id', 'error.fields');

    $this->getJson("/api/v1/stock-movements?product_id={$theirs['product']}", withKey($plain))->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/computers?zone_id={$theirs['zone']}", withKey($plain))->assertOk()->assertJsonCount(0, 'data');

    $product = Product::query()->withoutGlobalScope('club')->findOrFail($theirs['product']);
    $computer = ClubComputer::query()->withoutGlobalScope('club')->findOrFail($theirs['computer']);
    expect((string) $product->price)->toBe('500.00')
        ->and($product->quantity)->toBe(10)
        ->and($computer->is_reserved)->toBeFalse()
        ->and($computer->status)->not->toBe('offline');
});

test('each endpoint requires its scope', function (string $method, string $uri, string $scope) {
    [, $mine] = twoClubsWithData();
    [$club] = [Club::query()->where('slug', 'mine')->firstOrFail()];
    $owner = User::query()->whereKey(ClubMembership::query()->withoutGlobalScope('club')->where('club_id', $club->id)->value('user_id'))->firstOrFail();
    [, $plain] = issueApiKey($club, $owner, array_values(array_diff(ALL_SCOPES, [$scope])));
    $uri = strtr($uri, ['{product}' => $mine['product'], '{computer}' => $mine['computer'], '{sale}' => $mine['sale']]);

    $this->json($method, $uri, [], withKey($plain, ['Idempotency-Key' => 'x']))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'insufficient_scope')
        ->assertJsonPath('error.required_scopes', [$scope]);
})->with([
    ['GET', '/api/v1/products', 'products:read'],
    ['GET', '/api/v1/products/{product}', 'products:read'],
    ['GET', '/api/v1/product-categories', 'products:read'],
    ['GET', '/api/v1/stock-movements', 'products:read'],
    ['POST', '/api/v1/products', 'products:write'],
    ['PATCH', '/api/v1/products/{product}', 'products:write'],
    ['POST', '/api/v1/stock-movements', 'stock:write'],
    ['GET', '/api/v1/sales', 'sales:read'],
    ['GET', '/api/v1/sales/{sale}', 'sales:read'],
    ['GET', '/api/v1/zones', 'computers:read'],
    ['GET', '/api/v1/computers', 'computers:read'],
    ['GET', '/api/v1/computers/{computer}', 'computers:read'],
    ['PUT', '/api/v1/computers/{computer}/reservation', 'bookings:write'],
    ['POST', '/api/v1/computers/control', 'computers:control'],
    ['GET', '/api/v1/tariffs', 'tariffs:read'],
    ['GET', '/api/v1/members', 'members:read'],
]);

test('products are created and updated through the existing business rules', function () {
    [$club, $mine, , , $plain] = twoClubsWithData();

    $created = $this->postJson('/api/v1/products', ['sku' => 'API-1', 'name' => 'Api tea', 'product_category_id' => $mine['category'], 'price' => '250.50', 'quantity' => 4], withKey($plain))
        ->assertCreated()
        ->assertJsonPath('data.quantity', 4)
        ->assertJsonPath('data.category', 'Mine drinks')
        ->json('data');

    $this->postJson('/api/v1/products', ['sku' => 'API-1', 'name' => 'Dup', 'product_category_id' => $mine['category'], 'price' => 1, 'quantity' => 0], withKey($plain))
        ->assertUnprocessable()->assertJsonValidationErrors('sku', 'error.fields');
    $this->patchJson("/api/v1/products/{$created['id']}", ['price' => 300, 'name' => 'Api green tea'], withKey($plain))
        ->assertOk()->assertJsonPath('data.price', '300.00')->assertJsonPath('data.name', 'Api green tea');
    $this->patchJson("/api/v1/products/{$created['id']}", ['quantity' => 999], withKey($plain))
        ->assertUnprocessable()->assertJsonValidationErrors('quantity', 'error.fields');

    $product = Product::query()->withoutGlobalScope('club')->findOrFail($created['id']);
    expect($product->club_id)->toBe($club->id)->and($product->quantity)->toBe(4);

    $logs = ActivityLog::query()->withoutGlobalScope('club')->where('club_id', $club->id)->whereIn('event', ['product.created', 'product.updated', 'stock.received'])->get();
    expect($logs->pluck('event')->sort()->values()->all())->toBe(['product.created', 'product.updated', 'stock.received'])
        ->and($logs->pluck('details.api_key')->unique()->all())->toBe([substr($plain, 0, 12)])
        ->and($logs->pluck('ip_address')->unique()->all())->toBe(['127.0.0.1']);
});

test('the same SKU may exist in another club', function () {
    [, $mine, , , $plain] = twoClubsWithData();

    $this->postJson('/api/v1/products', ['sku' => 'SKU-SAME-2', 'name' => 'A', 'product_category_id' => $mine['category'], 'price' => 1, 'quantity' => 0], withKey($plain))->assertCreated();
    expect(Product::query()->withoutGlobalScope('club')->where('sku', 'SKU-SAME')->count())->toBe(2);
});

test('stock movements apply once per idempotency key and respect stock rules', function () {
    [, $mine, , , $plain] = twoClubsWithData();
    $body = ['product_id' => $mine['product'], 'type' => 'write_off', 'quantity' => 3];

    $this->postJson('/api/v1/stock-movements', $body, withKey($plain))->assertStatus(428);
    $first = $this->postJson('/api/v1/stock-movements', $body, withKey($plain, ['Idempotency-Key' => 'wo-1']))->assertCreated()->json('data');
    $replay = $this->postJson('/api/v1/stock-movements', $body, withKey($plain, ['Idempotency-Key' => 'wo-1']))->assertCreated()->json('data');
    $this->postJson('/api/v1/stock-movements', [...$body, 'quantity' => 100], withKey($plain, ['Idempotency-Key' => 'wo-2']))
        ->assertUnprocessable()->assertJsonValidationErrors('quantity', 'error.fields');

    expect($replay)->toBe($first)
        ->and($first['balance_after'])->toBe(7)
        ->and(Product::query()->withoutGlobalScope('club')->find($mine['product'])->quantity)->toBe(7);
});

test('computers can be reserved and released idempotently', function () {
    [$club, $mine, , , $plain] = twoClubsWithData();

    $this->putJson("/api/v1/computers/{$mine['computer']}/reservation", ['reserved' => true], withKey($plain))
        ->assertOk()->assertJsonPath('data.is_reserved', true)->assertJsonPath('data.status', 'reserved');
    $this->putJson("/api/v1/computers/{$mine['computer']}/reservation", ['reserved' => true], withKey($plain))->assertOk();
    $this->putJson("/api/v1/computers/{$mine['computer']}/reservation", ['reserved' => false], withKey($plain))
        ->assertOk()->assertJsonPath('data.is_reserved', false);

    expect(ActivityLog::query()->withoutGlobalScope('club')->where('club_id', $club->id)->where('event', 'computer.controlled')->count())->toBe(2);
});

test('power commands refuse computers in a customer session and limit batch size', function () {
    [, $mine, , , $plain] = twoClubsWithData();
    $computer = ClubComputer::query()->withoutGlobalScope('club')->findOrFail($mine['computer']);
    $computer->forceFill(['current_session_id' => 'session-1', 'status' => 'online'])->save();
    $headers = fn (string $key) => withKey($plain, ['Idempotency-Key' => $key]);

    $this->postJson('/api/v1/computers/control', ['computer_ids' => [$computer->id], 'action' => 'power_off'], withKey($plain))->assertStatus(428);
    $this->postJson('/api/v1/computers/control', ['computer_ids' => [$computer->id], 'action' => 'power_off'], $headers('c1'))
        ->assertStatus(409)->assertJsonPath('error.code', 'conflict')->assertJsonPath('error.computer_ids', [$computer->id]);
    expect($computer->refresh()->status)->toBe('online');

    $this->postJson('/api/v1/computers/control', ['computer_ids' => range(1, 51), 'action' => 'restart'], $headers('c2'))
        ->assertUnprocessable();

    $computer->forceFill(['current_session_id' => null])->save();
    $this->postJson('/api/v1/computers/control', ['computer_ids' => [$computer->id], 'action' => 'power_off'], $headers('c3'))
        ->assertOk()->assertJsonPath('data.updated', 1);
    expect($computer->refresh()->status)->toBe('offline');
});

test('members are listed with masked phones and without e-mail', function () {
    [, , , , $plain] = twoClubsWithData();

    $response = $this->getJson('/api/v1/members', withKey($plain))->assertOk();
    $member = $response->json('data.0');
    $user = User::query()->findOrFail($member['id']);

    expect($member['phone_masked'])->toMatch('/^\+\d \*\*\* \*\*\* \d{2} \d{2}$/')
        ->and($response->getContent())->not->toContain($user->phone)->not->toContain((string) $user->email);
});

test('cursor pagination walks the whole list', function () {
    [$club, $mine, , , $plain] = twoClubsWithData();
    app(CurrentClub::class)->set($club);
    foreach (range(1, 4) as $i) {
        Product::query()->create(['sku' => "P-{$i}", 'name' => "Page {$i}", 'category' => 'x', 'product_category_id' => $mine['category'], 'price' => 1, 'quantity' => 1]);
    }

    $first = $this->getJson('/api/v1/products?limit=2', withKey($plain))->assertOk()->assertJsonCount(2, 'data');
    $second = $this->getJson('/api/v1/products?limit=2&cursor='.$first->json('meta.next_cursor'), withKey($plain))->assertJsonCount(2, 'data');
    $third = $this->getJson('/api/v1/products?limit=2&cursor='.$second->json('meta.next_cursor'), withKey($plain))->assertJsonCount(1, 'data');

    expect($third->json('meta.next_cursor'))->toBeNull();
    $this->getJson('/api/v1/products?limit=500', withKey($plain))->assertUnprocessable();
});

test('list endpoints do not run a query per record', function (string $endpoint) {
    [$club, $mine, , , $plain] = twoClubsWithData();
    $this->getJson($endpoint, withKey($plain))->assertOk();

    $count = function () use ($endpoint, $plain): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson($endpoint, withKey($plain))->assertOk();

        return count(DB::getQueryLog());
    };
    $before = $count();

    app(CurrentClub::class)->set($club);
    $zone = ClubZone::query()->create(['name' => 'More']);
    foreach (range(2, 6) as $i) {
        $user = User::factory()->create();
        Product::query()->create(['sku' => "N-{$i}", 'name' => "N {$i}", 'category' => 'x', 'product_category_id' => $mine['category'], 'price' => 1, 'quantity' => 1]);
        ClubComputer::query()->create(['club_zone_id' => $zone->id, 'number' => $i]);
        Tariff::query()->create(['name' => "T {$i}", 'duration_minutes' => 60, 'price' => 1, 'is_active' => true])->zones()->attach($zone->id, ['club_id' => $club->id]);
    }

    expect($count())->toBe($before);
})->with(['/api/v1/products', '/api/v1/computers', '/api/v1/tariffs', '/api/v1/members', '/api/v1/zones']);
