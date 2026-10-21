<?php

use App\Models\ActivityLog;
use App\Models\ClubComputer;
use App\Models\ClubZone;
use App\Models\MemberBalance;
use App\Models\User;
use App\Services\AgentTariffPurchaseService;
use App\Services\ClubComputerSessions;
use App\Services\CurrentClub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

function minuteApi(User $user, ?string $deviceToken = null): array
{
    app('auth')->forgetGuards();
    app(CurrentClub::class)->clear();
    $token = $user->createToken('PC', ['club:'.$user->memberships()->withoutGlobalScope('club')->value('club_id')])->plainTextToken;

    return array_filter(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json', 'X-Agent-Token' => $deviceToken]);
}

test('the hourly price of a zone is set in the zone form and shown in tariffs', function () {
    $owner = User::factory()->create();
    $zone = ClubZone::query()->create(['name' => 'VIP']);
    ClubComputer::query()->create(['club_zone_id' => $zone->id, 'number' => 1]);

    $this->actingAs($owner)->put(route('club-map.update', $zone), [
        'name' => 'VIP', 'icon' => 'desktop', 'theme' => 'purple', 'status' => 'available', 'is_featured' => 0, 'sort_order' => 0, 'price_per_hour' => '900',
    ])->assertSessionHasNoErrors();
    expect($zone->fresh()->price_per_hour)->toBe('900.00');

    $this->get(route('tariffs.index'))->assertOk()
        ->assertSee('Поминутная оплата по зонам')
        ->assertSee('900,00 KZT')
        ->assertSee('15,00 KZT');
    $this->get(route('club-map.index'))->assertOk()->assertSee('data-price-per-hour="900.00"', false);

    $this->put(route('club-map.update', $zone), [
        'name' => 'VIP', 'icon' => 'desktop', 'theme' => 'purple', 'status' => 'available', 'is_featured' => 0, 'sort_order' => 0, 'price_per_hour' => '',
    ])->assertSessionHasNoErrors();
    expect($zone->fresh()->price_per_hour)->toBeNull();
});

test('a customer buys minutes at the hourly price of the zone, main balance first', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-15 10:00:00', 'UTC'));
    $customer = User::factory()->create();
    MemberBalance::query()->where('user_id', $customer->id)->update(['balance' => 100, 'bonus_balance' => 50]);
    allowMixedPayment();
    $zone = ClubZone::query()->create(['name' => 'Hall', 'price_per_hour' => 500]);
    $computer = ClubComputer::query()->create(['club_zone_id' => $zone->id, 'number' => 1]);
    $deviceToken = app(ClubComputerSessions::class)->issueAgentToken($computer);

    $this->getJson(route('api.guest.minutes', ['computer_id' => $computer->id]), minuteApi($customer))->assertOk()
        ->assertJsonPath('available', true)
        ->assertJsonPath('zone', 'Hall')
        ->assertJsonPath('price_per_hour', '500.00');

    // 500 an hour: 17 minutes = 141.666… → 141.67 (100 from the main balance, 41.67 bonus).
    $this->postJson(route('api.guest.minutes.purchase'), ['computer_id' => $computer->id, 'minutes' => 17], minuteApi($customer))->assertForbidden();
    $this->postJson(route('api.guest.minutes.purchase'), ['computer_id' => $computer->id, 'minutes' => 17], minuteApi($customer, $deviceToken))
        ->assertOk()
        ->assertJsonPath('minutes_added', 17)
        ->assertJsonPath('new_balance', '0.00')
        ->assertJsonPath('new_bonus', '8.33');

    expect($computer->fresh())
        ->current_session_user_id->toBe($customer->id)
        ->and($computer->fresh()->session_ends_at->equalTo(now()->addMinutes(17)))->toBeTrue()
        ->and(ActivityLog::query()->where('event', 'time.purchased')->sole()->details)->toMatchArray([
            'amount' => '141.67', 'main_balance_amount' => '100.00', 'bonus_balance_amount' => '41.67', 'duration_minutes' => 17,
        ]);

    // Not enough money left for another hour.
    $this->postJson(route('api.guest.minutes.purchase'), ['computer_id' => $computer->id, 'minutes' => 60], minuteApi($customer, $deviceToken))
        ->assertJsonValidationErrors('balance');

    $this->getJson(route('api.guest.history'), minuteApi($customer))->assertOk()
        ->assertJsonPath('data.0.kind', 'purchase')
        ->assertJsonPath('data.0.title', 'Поминутная оплата: 17 мин.')
        ->assertJsonPath('data.0.amount', '-100.00')
        ->assertJsonPath('data.0.bonus_amount', '-41.67');
});

test('minutes are not sold in a zone without an hourly price, out of range or on someone else\'s session', function () {
    $customer = User::factory()->create();
    $other = User::factory()->create();
    MemberBalance::query()->where('user_id', $customer->id)->update(['balance' => 10000]);
    $plain = ClubComputer::query()->create(['club_zone_id' => ClubZone::query()->create(['name' => 'Plain'])->id, 'number' => 1]);
    $priced = ClubComputer::query()->create(['club_zone_id' => ClubZone::query()->create(['name' => 'Hall', 'price_per_hour' => 600])->id, 'number' => 2]);
    $priced->update(['current_session_id' => 's', 'current_session_user_id' => $other->id, 'session_ends_at' => now()->addHour()]);

    $this->getJson(route('api.guest.minutes', ['computer_id' => $plain->id]), minuteApi($customer))->assertOk()->assertJsonPath('available', false);
    $this->postJson(route('api.guest.minutes.purchase'), ['computer_id' => $plain->id, 'minutes' => 30], minuteApi($customer))->assertJsonValidationErrors('minutes');
    $this->postJson(route('api.guest.minutes.purchase'), ['computer_id' => $priced->id, 'minutes' => 0], minuteApi($customer))->assertJsonValidationErrors('minutes');
    $this->postJson(route('api.guest.minutes.purchase'), ['computer_id' => $priced->id, 'minutes' => 721], minuteApi($customer))->assertJsonValidationErrors('minutes');
    $this->postJson(route('api.guest.minutes.purchase'), ['computer_id' => $priced->id, 'minutes' => 30], minuteApi($customer))->assertJsonValidationErrors('computer_id');

    expect(MemberBalance::query()->where('user_id', $customer->id)->value('balance'))->toBe('10000.00');
});

test('the price of minutes is the hourly price divided by 60, rounded to the cent', function (string $hour, int $minutes, int $cents) {
    expect(AgentTariffPurchaseService::minutesPriceInCents($hour, $minutes))->toBe($cents);
})->with([
    ['600.00', 1, 1000],
    ['600.00', 90, 90000],
    ['500.00', 17, 14167],
    ['1.00', 1, 2],
    ['0.50', 1, 1],
]);
