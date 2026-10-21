<?php

use App\Models\ActivityLog;
use App\Models\Club;
use App\Models\ClubComputer;
use App\Models\ClubMembership;
use App\Models\ClubZone;
use App\Models\MemberBalance;
use App\Models\Product;
use App\Models\ShopOrder;
use App\Models\Tariff;
use App\Models\User;
use App\Services\ClubComputerSessions;
use App\Services\CurrentClub;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Merges finance settings into the current club's settings. */
function loyaltySettings(array $finances): void
{
    $club = Club::query()->findOrFail(app(CurrentClub::class)->id());
    $club->forceFill(['settings' => array_replace_recursive($club->settings ?? [], ['finances' => $finances])])->save();
    app(CurrentClub::class)->set($club);
}

/** A customer with money, a PC and a tariff with "Apply discount"; returns [customer, membership, computer, tariff, headers]. */
function loyaltyCustomer(array $tariff = []): array
{
    User::factory()->create(); // the club owner
    $customer = User::factory()->create();
    $membership = ClubMembership::query()->where('user_id', $customer->id)->firstOrFail();
    $membership->update(['role' => 'client']);
    MemberBalance::query()->where('user_id', $customer->id)->update(['balance' => 5000, 'bonus_balance' => 0]);
    $computer = ClubComputer::query()->create(['club_zone_id' => ClubZone::query()->create(['name' => 'Hall', 'price_per_hour' => 600])->id, 'number' => 3]);
    $tariff = Tariff::query()->create($tariff + [
        'name' => 'Час', 'duration_minutes' => 60, 'price' => 1000, 'currency' => 'KZT',
        'payment_method' => 'main_or_bonus', 'discount_enabled' => true, 'is_active' => true,
    ]);
    $token = $customer->createToken('PC', ['club:'.$membership->club_id])->plainTextToken;
    $headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json', 'X-Agent-Token' => app(ClubComputerSessions::class)->issueAgentToken($computer)];

    return [$customer, $membership, $computer, $tariff, $headers];
}

test('the manager sets up cumulative discount levels on the finance page', function () {
    $this->actingAs(User::factory()->create());

    $this->post(route('settings.save'), [
        'section' => 'finances',
        'currency' => 'KZT',
        'loyalty_enabled' => '1',
        'loyalty_levels' => [
            ['spent' => '50000', 'percent' => '10'],
            ['spent' => '', 'percent' => ''],
            ['spent' => '10000', 'percent' => '5.5'],
        ],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $finances = app(CurrentClub::class)->get()->fresh()->settings['finances'];
    expect($finances['loyalty_enabled'])->toBeTrue()
        ->and($finances['loyalty_levels'])->toBe([['spent' => 10000, 'percent' => 5.5], ['spent' => 50000, 'percent' => 10]]);
    expect(ActivityLog::query()->where('event', 'settings.updated')->latest('id')->first()->details['loyalty_levels'])->toBe('— → 10000: 5.5%; 50000: 10%');

    $this->get(route('settings.finances'))->assertOk()
        ->assertSee('name="loyalty_levels[1][spent]" value="50000"', false)
        ->assertSee('Накопительная скидка');
    $this->get(route('settings.api'))->assertOk();

    // Saving the currency alone keeps the levels; the empty field of the form removes them.
    $this->post(route('settings.save'), ['section' => 'finances', 'currency' => 'RUB'])->assertSessionHasNoErrors();
    expect(app(CurrentClub::class)->get()->fresh()->settings['finances']['loyalty_levels'])->toHaveCount(2);
    $this->post(route('settings.save'), ['section' => 'finances', 'currency' => 'RUB', 'loyalty_levels' => ''])->assertSessionHasNoErrors();
    expect(app(CurrentClub::class)->get()->fresh()->settings['finances']['loyalty_levels'])->toBe([]);
});

test('discount levels are validated', function (array $levels, string $error) {
    $this->actingAs(User::factory()->create());

    $this->post(route('settings.save'), ['section' => 'finances', 'currency' => 'KZT', 'loyalty_levels' => $levels])
        ->assertSessionHasErrors($error);
})->with([
    'percent over 100' => [[['spent' => 1000, 'percent' => 101]], 'loyalty_levels.0.percent'],
    'no percent' => [[['spent' => 1000, 'percent' => '']], 'loyalty_levels.0.percent'],
    'zero spent' => [[['spent' => 0, 'percent' => 5]], 'loyalty_levels.0.spent'],
    'same spent twice' => [[['spent' => 1000, 'percent' => 5], ['spent' => 1000, 'percent' => 7]], 'loyalty_levels.0.spent'],
    'too many' => [array_fill(0, 21, ['spent' => 1, 'percent' => 1]), 'loyalty_levels'],
]);

test('without the cumulative discount tariff prices are the same as before', function () {
    [, $membership, , , $headers] = loyaltyCustomer();
    $membership->update(['discount' => 12.5, 'total_spent' => 100000]);
    loyaltySettings(['loyalty_enabled' => false, 'loyalty_levels' => [['spent' => 1000, 'percent' => 50]]]);

    // The former price: tariff price minus the personal discount, rounded half up to the cent.
    $before = fn (int $cents, float $discount): string => sprintf('%.2f', ($cents - intdiv($cents * (int) round($discount * 100) + 5000, 10000)) / 100);

    $this->getJson(route('api.tariffs.index'), $headers)->assertOk()
        ->assertJsonPath('data.0.price', $before(100000, 12.5))
        ->assertJsonPath('data.0.price', '875.00');
});

test('a customer who has spent enough gets the level discount, the larger of personal and cumulative', function () {
    [$customer, $membership, $computer, $tariff, $headers] = loyaltyCustomer();
    Tariff::query()->create(['name' => 'Без скидки', 'duration_minutes' => 30, 'price' => 1000, 'currency' => 'KZT', 'payment_method' => 'main_or_bonus', 'discount_enabled' => false, 'is_active' => true]);
    $membership->update(['discount' => 5]);
    loyaltySettings(['loyalty_enabled' => true, 'loyalty_levels' => [['spent' => 1500, 'percent' => 10], ['spent' => 3000, 'percent' => 20]]]);

    // Spent 0: the personal 5%.
    $this->getJson(route('api.tariffs.index'), $headers)->assertOk()->assertJsonPath('data.1.price', '950.00')->assertJsonPath('data.0.price', '1000.00');
    $this->postJson(route('api.tariffs.purchase', $tariff), ['computer_id' => $computer->id], $headers)->assertOk()->assertJsonPath('new_balance', '4050.00');
    expect($membership->refresh()->total_spent)->toBe('950.00');

    // 600 KZT for an hour by the minute: spent 1550, the 10% level.
    $this->postJson(route('api.guest.minutes.purchase'), ['computer_id' => $computer->id, 'minutes' => 60], $headers)->assertOk();
    expect($membership->refresh()->total_spent)->toBe('1550.00');
    $this->getJson(route('api.tariffs.index'), $headers)->assertOk()->assertJsonPath('data.1.price', '900.00')->assertJsonPath('data.0.price', '1000.00');

    $this->postJson(route('api.tariffs.purchase', $tariff), ['computer_id' => $computer->id], $headers)->assertOk()->assertJsonPath('new_balance', '2550.00');
    expect($membership->refresh()->total_spent)->toBe('2450.00');

    $this->actingAs(User::query()->whereKey(ClubMembership::query()->where('role', 'owner')->value('user_id'))->firstOrFail());
    $this->get(route('users.index'))->assertOk()->assertSee('10.00%')->assertSee('2 450.00')->assertSee('накопительная');
});

test('bonus payments do not count as spending', function () {
    [$customer, $membership, $computer, $tariff, $headers] = loyaltyCustomer();
    MemberBalance::query()->where('user_id', $customer->id)->update(['balance' => 400, 'bonus_balance' => 1000]);
    allowMixedPayment();

    $this->postJson(route('api.tariffs.purchase', $tariff), ['computer_id' => $computer->id], $headers)->assertOk();

    expect($membership->refresh()->total_spent)->toBe('400.00');
});

test('agent shop orders count as spending and a cancelled order is taken back', function () {
    [$customer, $membership, $computer, , $headers] = loyaltyCustomer();
    $cola = Product::query()->create(['sku' => 'COLA', 'name' => 'Cola', 'category' => 'Напитки', 'price' => 350, 'quantity' => 10]);

    $this->postJson(route('api.guest.shop.purchase'), ['computer_id' => $computer->id, 'items' => [['id' => $cola->id, 'quantity' => 2]]], $headers)->assertOk();
    expect($membership->refresh()->total_spent)->toBe('700.00');

    $this->actingAs(User::query()->whereKey(ClubMembership::query()->where('role', 'owner')->value('user_id'))->firstOrFail());
    $this->post(route('shop-orders.cancel', ShopOrder::query()->firstOrFail()))->assertRedirect();
    expect($membership->refresh()->total_spent)->toBe('0.00');
});

test('the migration counts past spending from the activity log and converts the draft settings', function () {
    [$customer, $membership] = loyaltyCustomer();
    $owner = ClubMembership::query()->where('role', 'owner')->value('user_id');
    $log = fn (string $event, int $userId, array $details, ?int $subjectId = null) => ActivityLog::query()->forceCreate(['event' => $event, 'description' => $event, 'user_id' => $userId, 'subject_type' => 'x', 'subject_id' => $subjectId, 'details' => $details, 'ip_address' => '127.0.0.1']);
    $log('tariff.purchased', $customer->id, ['amount' => '1000.00', 'main_balance_amount' => '700.00', 'bonus_balance_amount' => '300.00']);
    $log('time.purchased', $customer->id, ['amount' => '250.50', 'main_balance_amount' => '250.50']);
    $log('shop.purchased', $customer->id, ['amount' => '500.00']);
    $log('shop.order_cancelled', $owner, ['amount' => '500.00'], $customer->id);
    $log('tariff.purchased', $owner, ['amount' => '40.00', 'main_balance_amount' => '40.00']);
    $membership->update(['total_spent' => 99999]);
    $club = Club::query()->findOrFail($membership->club_id);
    $club->forceFill(['settings' => ['finances' => ['currency' => 'KZT', 'tax_rate' => '0', 'loyalty_type' => 'cumulative', 'loyalty_enabled' => true, 'loyalty_rules' => [['threshold' => 20000, 'discount' => 7]]]]])->save();

    (require database_path('migrations/2026_10_18_100000_backfill_loyalty_spending.php'))->up();

    expect($membership->refresh()->total_spent)->toBe('950.50')
        ->and(ClubMembership::query()->where('user_id', $owner)->value('total_spent'))->toBe('40.00')
        ->and($club->refresh()->settings['finances'])->toBe(['currency' => 'KZT', 'loyalty_enabled' => true, 'loyalty_levels' => [['spent' => 20000, 'percent' => 7]]]);
});
