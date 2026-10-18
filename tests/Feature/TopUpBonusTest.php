<?php

use App\Models\ActivityLog;
use App\Models\CashShift;
use App\Models\CashTransaction;
use App\Models\Club;
use App\Models\MemberBalance;
use App\Models\User;
use App\Services\CurrentClub;
use App\Services\GuestHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** A cashier with an open shift and a customer with 100 / 25 on the balances; returns [cashier, customer]. */
function bonusTopUpSetup(array $finances): array
{
    $club = Club::query()->findOrFail(app(CurrentClub::class)->id());
    $club->forceFill(['settings' => array_replace_recursive($club->settings ?? [], ['finances' => $finances])])->save();
    app(CurrentClub::class)->set($club);
    $cashier = User::factory()->create();
    $customer = User::factory()->create(['name' => 'Клиент бонус']);
    MemberBalance::query()->where('user_id', $customer->id)->update(['balance' => 100, 'bonus_balance' => 25]);
    CashShift::query()->create(['opened_by' => $cashier->id, 'status' => 'open', 'open_marker' => true, 'opening_balance' => 0, 'opened_at' => now()]);

    return [$cashier, $customer];
}

function topUp(User $customer, float $amount, string $type = 'balance')
{
    return test()->post(route('cash-register.balance-top-ups.store'), ['user_id' => $customer->id, 'amount' => $amount, 'balance_type' => $type, 'payment_method' => 'cash']);
}

test('the manager sets up top-up bonus levels on the finance page', function () {
    $this->actingAs(User::factory()->create());

    $this->post(route('settings.save'), [
        'section' => 'finances',
        'currency' => 'KZT',
        'topup_bonus_enabled' => '1',
        'topup_bonus_levels' => [['amount' => '10000', 'percent' => '10'], ['amount' => '', 'percent' => ''], ['amount' => '2000', 'percent' => '5']],
    ])->assertSessionHasNoErrors();

    $finances = app(CurrentClub::class)->get()->fresh()->settings['finances'];
    expect($finances['topup_bonus_enabled'])->toBeTrue()
        ->and($finances['topup_bonus_levels'])->toBe([['amount' => 2000, 'percent' => 5], ['amount' => 10000, 'percent' => 10]]);
    $this->get(route('settings.finances'))->assertOk()
        ->assertSee('name="topup_bonus_levels[1][amount]" value="10000"', false)
        ->assertSee('href="#topup-bonus"', false);

    $this->post(route('settings.save'), ['section' => 'finances', 'currency' => 'KZT', 'topup_bonus_levels' => [['amount' => 1000, 'percent' => 150]]])
        ->assertSessionHasErrors('topup_bonus_levels.0.percent');
});

test('a top-up of the main balance adds the bonus of the level it reaches; cash takes only the money', function () {
    [$cashier, $customer] = bonusTopUpSetup(['topup_bonus_enabled' => true, 'topup_bonus_levels' => [['amount' => 2000, 'percent' => 5], ['amount' => 10000, 'percent' => 10]]]);
    $balance = fn () => MemberBalance::query()->where('user_id', $customer->id)->sole();

    // Below the first level: no bonus.
    $this->actingAs($cashier);
    topUp($customer, 1000)->assertSessionHas('status', 'Баланс пользователя пополнен.');
    expect($balance()->bonus_balance)->toBe('25.00');

    // 2 500 → 5% = 125.
    topUp($customer, 2500)->assertSessionHas('status', 'Баланс пользователя пополнен. Бонус за пополнение: 125.00.');
    expect($balance()->balance)->toBe('3600.00')->and($balance()->bonus_balance)->toBe('150.00');
    $log = ActivityLog::query()->where('event', 'user.balance_topped_up')->latest('id')->first();
    expect($log->details)->toMatchArray(['amount' => 2500, 'bonus_amount' => 125, 'bonus_percent' => 5]);

    // 10 000.55 → 10% = 1 000.06 (rounded half up).
    topUp($customer, 10000.55);
    expect($balance()->bonus_balance)->toBe('1150.06')
        ->and((float) CashTransaction::query()->sum('amount'))->toBe(13500.55);

    // The customer's history shows the money and the bonus of the top-up.
    $entry = app(GuestHistory::class)->page($customer)['data'][0];
    expect($entry)->toMatchArray(['kind' => 'top_up', 'amount' => '10000.55', 'bonus_amount' => '1000.06']);
});

test('no bonus when it is switched off or the bonus balance is topped up', function () {
    [$cashier, $customer] = bonusTopUpSetup(['topup_bonus_enabled' => false, 'topup_bonus_levels' => [['amount' => 100, 'percent' => 50]]]);
    $this->actingAs($cashier);

    topUp($customer, 1000)->assertSessionHas('status', 'Баланс пользователя пополнен.');
    expect(MemberBalance::query()->where('user_id', $customer->id)->value('bonus_balance'))->toBe('25.00');

    $club = Club::query()->findOrFail(app(CurrentClub::class)->id());
    $club->forceFill(['settings' => array_replace_recursive($club->settings, ['finances' => ['topup_bonus_enabled' => true]])])->save();
    app(CurrentClub::class)->set($club);
    topUp($customer, 1000, 'bonus_balance');
    expect(MemberBalance::query()->where('user_id', $customer->id)->value('bonus_balance'))->toBe('1025.00');
});
