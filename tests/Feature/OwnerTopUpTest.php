<?php

use App\Models\ActivityLog;
use App\Models\CashTransaction;
use App\Models\Club;
use App\Models\ClubMembership;
use App\Models\MemberBalance;
use App\Models\User;
use App\Services\CurrentClub;
use App\Services\GuestHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function topUpCustomer(): array
{
    $owner = User::factory()->create(); // the first user owns the club
    $customer = User::factory()->create(['name' => 'Арман']);
    $membership = ClubMembership::query()->where('user_id', $customer->id)->sole();
    $membership->update(['role' => 'client']);
    MemberBalance::query()->where('user_id', $customer->id)->update(['balance' => 100, 'bonus_balance' => 10]);

    return [$owner, $customer, $membership];
}

test('the owner tops up a customer balance without the cash register, while editing the customer', function () {
    [$owner, $customer, $membership] = topUpCustomer();

    $this->actingAs($owner)->get(route('users.index'))->assertOk()->assertSee('name="top_up_amount"', false);
    $this->patch(route('users.update', $membership), ['name' => 'Арман', 'top_up_amount' => '500', 'top_up_balance' => 'balance'])
        ->assertSessionHasNoErrors()->assertSessionHas('status', 'Данные клиента сохранены. Баланс пополнен на 500.00 без кассы.');
    $this->patch(route('users.update', $membership), ['name' => 'Арман', 'top_up_amount' => '40.5', 'top_up_balance' => 'bonus_balance'])->assertSessionHasNoErrors();

    $balance = MemberBalance::query()->where('user_id', $customer->id)->sole();
    expect($balance->balance)->toBe('600.00')
        ->and($balance->bonus_balance)->toBe('50.50')
        // No cash transaction: the shift and its report do not change.
        ->and(CashTransaction::query()->exists())->toBeFalse()
        ->and(ActivityLog::query()->where('event', 'user.balance_topped_up')->where('user_id', $owner->id)->count())->toBe(2);
    $entries = app(GuestHistory::class)->page($customer)['data'];
    expect($entries[1])->toMatchArray(['kind' => 'top_up', 'amount' => '500.00', 'note' => 'без кассы (владелец)'])
        ->and($entries[0])->toMatchArray(['kind' => 'top_up', 'bonus_amount' => '40.50']);

    // Saving without an amount changes nothing.
    $this->patch(route('users.update', $membership), ['name' => 'Арман Сейтов'])->assertSessionHasNoErrors();
    expect(MemberBalance::query()->where('user_id', $customer->id)->value('balance'))->toBe('600.00');
});

test('other staff cannot top up without the cash register', function (string $role) {
    [, $customer, $membership] = topUpCustomer();
    $staff = User::factory()->create();
    ClubMembership::query()->where('user_id', $staff->id)->update(['role' => $role]);

    $this->actingAs($staff)->get(route('users.index'))->assertOk()->assertDontSee('name="top_up_amount"', false);
    $this->patch(route('users.update', $membership), ['name' => 'Арман', 'top_up_amount' => '500', 'top_up_balance' => 'balance'])
        ->assertSessionHasErrors('top_up_amount');
    expect(MemberBalance::query()->where('user_id', $customer->id)->value('balance'))->toBe('100.00');
})->with(['admin', 'manager', 'cashier']);

test('a worker of another club plays here: this club changes the discount and balance, not the account', function () {
    [$owner, $customer, $membership] = topUpCustomer();
    $otherClub = Club::query()->create(['name' => 'Другой клуб', 'slug' => 'worker-elsewhere']);
    $current = app(CurrentClub::class)->get();
    app(CurrentClub::class)->set($otherClub);
    ClubMembership::query()->create(['user_id' => $customer->id, 'role' => 'admin', 'discount' => 0]);
    app(CurrentClub::class)->set($current);

    $this->actingAs($owner)->get(route('users.index'))->assertOk()
        ->assertSee('data-account-locked', false)->assertDontSee(route('users.password', $membership), false);
    $this->patch(route('users.update', $membership), ['name' => 'Арман', 'email' => $customer->email, 'discount' => '7', 'top_up_amount' => '300', 'top_up_balance' => 'balance'])
        ->assertSessionHasNoErrors();
    $this->patch(route('users.update', $membership), ['name' => 'Чужое имя', 'email' => $customer->email])->assertSessionHasErrors('name');
    $this->post(route('users.password', $membership))->assertForbidden();

    expect($membership->fresh()->discount)->toBe('7.00')
        ->and(MemberBalance::query()->where('user_id', $customer->id)->sole()->balance)->toBe('400.00')
        ->and($customer->fresh()->name)->toBe('Арман');
});
