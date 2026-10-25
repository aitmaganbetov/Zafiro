<?php

use App\Models\ActivityLog;
use App\Models\Club;
use App\Models\ClubComputer;
use App\Models\ClubMembership;
use App\Models\ClubZone;
use App\Models\MemberBalance;
use App\Models\SessionPurchase;
use App\Models\Tariff;
use App\Models\TimePackage;
use App\Models\User;
use App\Services\ClubComputerSessions;
use App\Services\CurrentClub;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Refunds are counted to the second: time stands still unless a test travels.
beforeEach(fn () => $this->freezeTime());

function timeFinances(array $finances): void
{
    $club = Club::query()->findOrFail(app(CurrentClub::class)->id());
    $club->forceFill(['settings' => array_replace_recursive($club->settings ?? [], ['finances' => $finances])])->save();
    app(CurrentClub::class)->set($club);
}

/** A customer at a PC in a zone at 600 an hour; returns [customer, computer, agent headers]. */
function timeCustomer(float $main = 1000, float $bonus = 0): array
{
    User::factory()->create(); // the club owner
    $customer = User::factory()->create();
    ClubMembership::query()->where('user_id', $customer->id)->update(['role' => 'client']);
    MemberBalance::query()->where('user_id', $customer->id)->update(['balance' => $main, 'bonus_balance' => $bonus]);
    $computer = ClubComputer::query()->create(['club_zone_id' => ClubZone::query()->create(['name' => 'Hall', 'price_per_hour' => 600])->id, 'number' => 4]);
    $token = $customer->createToken('PC', ['club:'.$computer->club_id])->plainTextToken;
    $headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json', 'X-Agent-Token' => app(ClubComputerSessions::class)->issueAgentToken($computer)];

    return [$customer, $computer, $headers];
}

function timeTariff(array $attributes = []): Tariff
{
    return Tariff::query()->create($attributes + [
        'name' => 'Пакет', 'duration_minutes' => 120, 'price' => 1000, 'currency' => 'KZT',
        'payment_method' => 'main_or_bonus', 'discount_enabled' => true, 'is_active' => true,
    ]);
}

function balances(User $customer): array
{
    $balance = MemberBalance::query()->where('user_id', $customer->id)->sole();

    return [$balance->balance, $balance->bonus_balance];
}

function endSessionOf(ClubComputer $computer, array $headers)
{
    return test()->postJson(route('api.club-computers.session.end', $computer), ['session_id' => $computer->fresh()->current_session_id], $headers);
}

test('unused minutes go back to the balances they were paid from when the customer ends the session', function () {
    [$customer, $computer, $headers] = timeCustomer(main: 1000, bonus: 1000);
    timeFinances(['bonus_max_percent' => 50, 'allow_mixed_payment' => true]);

    // An hour for 600: half with bonuses (the club allows 50%), half from the main balance.
    $this->postJson(route('api.guest.minutes.purchase'), ['computer_id' => $computer->id, 'minutes' => 60, 'balance' => 'bonus'], $headers)->assertOk();
    expect(balances($customer))->toBe(['700.00', '700.00']);

    $this->travel(30)->minutes();
    endSessionOf($computer, $headers)->assertOk()
        ->assertJson(['refunded' => '150.00', 'refunded_bonus' => '150.00', 'kept_minutes' => 0]);

    expect(balances($customer))->toBe(['850.00', '850.00'])
        ->and($computer->fresh()->current_session_id)->toBeNull()
        ->and(SessionPurchase::query()->exists())->toBeFalse()
        ->and(ActivityLog::query()->where('event', 'time.refunded')->sole()->details)->toMatchArray(['main_balance_amount' => '150.00', 'bonus_balance_amount' => '150.00']);
    $this->getJson(route('api.guest.history'), $headers)->assertOk()->assertJsonPath('data.0.kind', 'refund')->assertJsonPath('data.0.amount', '150.00');
});

test('a tariff with a usage limit keeps its unused time; it is started later without paying', function () {
    [$customer, $computer, $headers] = timeCustomer();
    $tariff = timeTariff(['usage_limit_enabled' => true, 'usage_limit_minutes' => 30 * 24 * 60]);

    $this->postJson(route('api.tariffs.purchase', $tariff), ['computer_id' => $computer->id, 'balance' => 'main'], $headers)->assertOk()->assertJsonPath('minutes_added', 120);
    $this->travel(30)->minutes();
    endSessionOf($computer, $headers)->assertOk()->assertJson(['refunded' => '0.00', 'kept_minutes' => 90]);

    $package = TimePackage::query()->sole();
    expect($package->minutes_left)->toBe(90)
        ->and($package->expires_at->toDateString())->toBe(now()->subMinutes(30)->addDays(30)->toDateString());
    $this->getJson(route('api.guest.packages'), $headers)->assertOk()->assertJsonPath('data.0.minutes', 90)->assertJsonPath('data.0.name', 'Пакет');

    $this->postJson(route('api.guest.packages.start', $package), ['computer_id' => $computer->id], $headers)->assertOk()->assertJsonPath('minutes_added', 90);
    expect(balances($customer))->toBe(['0.00', '0.00'])
        ->and(TimePackage::query()->exists())->toBeFalse()
        ->and($computer->fresh()->session_ends_at->diffInMinutes(now(), true))->toEqualWithDelta(90, 1);

    // Ended again, the rest is kept again until the same date.
    $this->travel(60)->minutes();
    endSessionOf($computer, $headers)->assertOk()->assertJson(['kept_minutes' => 30]);
    expect(TimePackage::query()->sole()->expires_at->toDateString())->toBe($package->expires_at->toDateString());
});

test('a tariff without a usage limit is not refunded; minutes bought on top are, the last part first', function () {
    [$customer, $computer, $headers] = timeCustomer(main: 2000);
    $tariff = timeTariff(['price' => 500, 'duration_minutes' => 60]);

    $this->postJson(route('api.tariffs.purchase', $tariff), ['computer_id' => $computer->id], $headers)->assertOk();
    $this->postJson(route('api.guest.minutes.purchase'), ['computer_id' => $computer->id, 'minutes' => 30], $headers)->assertOk();
    expect(balances($customer)[0])->toBe('1200.00');

    // 90 minutes in all; ended after 15: the 30 bought minutes come back in full, the tariff's 45 are lost.
    $this->travel(15)->minutes();
    endSessionOf($computer, $headers)->assertOk()->assertJson(['refunded' => '300.00', 'kept_minutes' => 0]);
    expect(balances($customer)[0])->toBe('1500.00')->and(TimePackage::query()->exists())->toBeFalse();
});

test('the customer picks the balance: main, or bonuses up to the club percent; some tariffs take no bonuses', function () {
    [$customer, $computer, $headers] = timeCustomer(main: 2000, bonus: 500);
    timeFinances(['bonus_max_percent' => 30, 'allow_mixed_payment' => true]);
    $tariff = timeTariff();
    $mainOnly = timeTariff(['name' => 'Только деньги', 'payment_method' => 'main_balance_only']);

    $this->getJson(route('api.tariffs.index'), $headers)->assertOk()
        ->assertJsonPath('data.0.bonus_allowed', true)->assertJsonPath('data.0.bonus_max_percent', 30)
        ->assertJsonPath('data.0.mixed_payment', true)->assertJsonPath('data.1.bonus_allowed', false);
    $this->getJson(route('api.guest.minutes', ['computer_id' => $computer->id]), $headers)->assertJsonPath('bonus_max_percent', 30)->assertJsonPath('mixed_payment', true);

    // 30% of 1000 with bonuses, the rest from the main balance.
    $this->postJson(route('api.tariffs.purchase', $tariff), ['computer_id' => $computer->id, 'balance' => 'bonus'], $headers)->assertOk()
        ->assertJson(['new_balance' => '1300.00', 'new_bonus' => '200.00']);
    $this->postJson(route('api.tariffs.purchase', $tariff), ['computer_id' => $computer->id, 'balance' => 'main'], $headers)->assertOk()
        ->assertJson(['new_balance' => '300.00', 'new_bonus' => '200.00']);
    $this->postJson(route('api.tariffs.purchase', $mainOnly), ['computer_id' => $computer->id, 'balance' => 'bonus'], $headers)
        ->assertUnprocessable()->assertJsonValidationErrors('balance');
    $this->postJson(route('api.tariffs.purchase', $tariff), ['computer_id' => $computer->id, 'balance' => 'cash'], $headers)
        ->assertUnprocessable()->assertJsonValidationErrors('balance');

    timeFinances(['bonus_max_percent' => 0]);
    $this->postJson(route('api.guest.minutes.purchase'), ['computer_id' => $computer->id, 'minutes' => 10, 'balance' => 'bonus'], $headers)
        ->assertUnprocessable()->assertJsonValidationErrors('balance');
});

test('without mixed payment a purchase is paid from one balance: the main one or bonuses for the whole price', function () {
    [$customer, $computer, $headers] = timeCustomer(main: 2000, bonus: 500);
    $tariff = timeTariff();

    // Bonuses may pay only 30%: without mixed payment the agent does not offer them at all.
    timeFinances(['bonus_max_percent' => 30]);
    $this->getJson(route('api.tariffs.index'), $headers)->assertOk()
        ->assertJsonPath('data.0.bonus_allowed', false)->assertJsonPath('data.0.bonus_max_percent', 0)->assertJsonPath('data.0.mixed_payment', false);
    $this->getJson(route('api.guest.minutes', ['computer_id' => $computer->id]), $headers)->assertJsonPath('bonus_max_percent', 0);
    $this->postJson(route('api.tariffs.purchase', $tariff), ['computer_id' => $computer->id, 'balance' => 'bonus'], $headers)
        ->assertUnprocessable()->assertJsonValidationErrors('balance');

    // 100%: bonuses pay the whole price or nothing.
    timeFinances(['bonus_max_percent' => 100]);
    $this->getJson(route('api.tariffs.index'), $headers)->assertJsonPath('data.0.bonus_allowed', true)->assertJsonPath('data.0.bonus_max_percent', 100);
    $this->postJson(route('api.tariffs.purchase', $tariff), ['computer_id' => $computer->id, 'balance' => 'bonus'], $headers)
        ->assertUnprocessable()->assertJsonPath('message', 'Бонусов не хватает на всю цену. Оплатите с основного баланса.');
    MemberBalance::query()->where('user_id', $customer->id)->update(['bonus_balance' => 1500]);
    $this->postJson(route('api.tariffs.purchase', $tariff), ['computer_id' => $computer->id, 'balance' => 'bonus'], $headers)->assertOk()
        ->assertJson(['new_balance' => '2000.00', 'new_bonus' => '500.00']);

    // Older agents (no choice): the main balance while it is enough.
    $this->postJson(route('api.tariffs.purchase', $tariff), ['computer_id' => $computer->id], $headers)->assertOk()
        ->assertJson(['new_balance' => '1000.00', 'new_bonus' => '500.00']);
    expect(balances($customer))->toBe(['1000.00', '500.00']);
});

test('the club chooses where the discount works: tariffs, hourly time or both', function () {
    [$customer, $computer, $headers] = timeCustomer();
    ClubMembership::query()->where('user_id', $customer->id)->update(['discount' => 10]);
    timeTariff();
    $prices = fn () => [
        $this->getJson(route('api.tariffs.index'), $headers)->json('data.0.price'),
        $this->getJson(route('api.guest.minutes', ['computer_id' => $computer->id]), $headers)->json('price_per_hour'),
    ];

    expect($prices())->toBe(['900.00', '600.00']);
    timeFinances(['discount_scope' => 'hourly']);
    expect($prices())->toBe(['1000.00', '540.00']);
    timeFinances(['discount_scope' => 'all']);
    expect($prices())->toBe(['900.00', '540.00']);

    // The hourly price charged is the one shown: 30 minutes at 540.
    $this->postJson(route('api.guest.minutes.purchase'), ['computer_id' => $computer->id, 'minutes' => 30], $headers)->assertOk()->assertJsonPath('new_balance', '730.00');
});

test('the finance page saves where the discount works and the bonus percent', function () {
    $this->actingAs(User::factory()->create());

    $this->post(route('settings.save'), ['section' => 'finances', 'currency' => 'KZT', 'discount_scope' => 'hourly', 'bonus_max_percent' => 40, 'allow_mixed_payment' => '1'])->assertSessionHasNoErrors();
    expect(app(CurrentClub::class)->get()->fresh()->settings['finances'])->toMatchArray(['discount_scope' => 'hourly', 'bonus_max_percent' => 40, 'allow_mixed_payment' => true]);
    $this->get(route('settings.finances'))->assertOk()->assertSee('name="allow_mixed_payment" value="1" class="settings-switch" checked', false);
    $this->get(route('settings.finances'))->assertOk()->assertSee('value="hourly" selected', false)->assertSee('href="#payment-rules"', false);

    $this->post(route('settings.save'), ['section' => 'finances', 'currency' => 'KZT', 'discount_scope' => 'never', 'bonus_max_percent' => 140])
        ->assertSessionHasErrors(['discount_scope', 'bonus_max_percent']);
});

test('with "play on the whole balance" the agent is told so, and the minutes the balance pays for are sold', function () {
    [$customer, $computer, $headers] = timeCustomer(main: 2000); // 600 an hour
    $this->getJson(route('api.guest.minutes', ['computer_id' => $computer->id]), $headers)->assertJsonPath('whole_balance', false);

    timeFinances(['allow_whole_balance' => true]);
    $this->getJson(route('api.guest.minutes', ['computer_id' => $computer->id]), $headers)->assertJsonPath('whole_balance', true);

    // 2000 at 600 an hour: 200 minutes (2000.00); the agent sends them from the main balance.
    $this->postJson(route('api.guest.minutes.purchase'), ['computer_id' => $computer->id, 'minutes' => 200, 'balance' => 'main'], $headers)
        ->assertOk()->assertJsonPath('new_balance', '0.00')->assertJsonPath('minutes_added', 200);

    // Left after an hour: the other 140 minutes come back.
    $this->travel(60)->minutes();
    endSessionOf($computer, $headers)->assertOk()->assertJson(['refunded' => '1400.00']);
    expect(balances($customer)[0])->toBe('1400.00');
});

test('the finance page saves "play on the whole balance"', function () {
    $this->actingAs(User::factory()->create());

    $this->post(route('settings.save'), ['section' => 'finances', 'currency' => 'KZT', 'discount_scope' => 'tariffs', 'bonus_max_percent' => 100, 'allow_whole_balance' => '1'])->assertSessionHasNoErrors();
    expect(app(CurrentClub::class)->get()->fresh()->settings['finances']['allow_whole_balance'])->toBeTrue();
    $this->get(route('settings.finances'))->assertOk()->assertSee('name="allow_whole_balance" value="1" class="settings-switch" checked', false);
});

test('per-minute billing by itself: the agent learns the setting and the pause after sign-in', function () {
    [, $computer, $headers] = timeCustomer(main: 1000);
    $this->getJson(route('api.guest.minutes', ['computer_id' => $computer->id]), $headers)
        ->assertJsonPath('auto_billing', false)->assertJsonPath('auto_billing_pause_minutes', 5);

    timeFinances(['auto_billing' => true, 'auto_billing_pause_minutes' => 3]);
    $this->getJson(route('api.guest.minutes', ['computer_id' => $computer->id]), $headers)
        ->assertJsonPath('auto_billing', true)->assertJsonPath('auto_billing_pause_minutes', 3);
});

test('the finance page saves per-minute billing after sign-in and its pause', function () {
    $this->actingAs(User::factory()->create());
    $form = ['section' => 'finances', 'currency' => 'KZT', 'discount_scope' => 'tariffs', 'bonus_max_percent' => 100];

    $this->post(route('settings.save'), $form + ['auto_billing' => '1', 'auto_billing_pause_minutes' => 7])->assertSessionHasNoErrors();
    expect(app(CurrentClub::class)->get()->fresh()->settings['finances'])->toMatchArray(['auto_billing' => true, 'auto_billing_pause_minutes' => 7]);
    $this->get(route('settings.finances'))->assertOk()->assertSee('name="auto_billing" value="1" class="settings-switch" checked', false)
        ->assertSee('name="auto_billing_pause_minutes" type="number" min="0" max="60" step="1" value="7"', false);

    $this->post(route('settings.save'), $form + ['auto_billing_pause_minutes' => 90])->assertSessionHasErrors('auto_billing_pause_minutes');
});
