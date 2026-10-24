<?php

use App\Models\ActivityLog;
use App\Models\CashTransaction;
use App\Models\Club;
use App\Models\ClubMembership;
use App\Models\MemberBalance;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\CashRegisterService;
use App\Services\CurrentClub;
use App\Services\GuestHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function refundCashier(): User
{
    $cashier = User::factory()->create(['name' => 'Айгерим']);
    ClubMembership::query()->where('user_id', $cashier->id)->update(['role' => 'cashier']);

    return $cashier;
}

/** The owner gives (or takes) the refund right of this cashier (Settings → Rights). */
function giveRefunds(User $cashier, bool $allowed): void
{
    ClubMembership::query()->where('user_id', $cashier->id)->sole()->forceFill(['permissions' => ['cash_refunds' => $allowed]])->save();
    app(CurrentClub::class)->clear();
}

test('a refund of a sale pays the money out of the shift and puts the goods back to the stock', function () {
    $owner = User::factory()->create();
    $cash = app(CashRegisterService::class);
    $shift = $cash->open(1000, $owner->id);
    $cola = Product::factory()->create(['name' => 'Coca Cola', 'price' => 700, 'quantity' => 10]);
    $sale = $cash->sale([['product_id' => $cola->id, 'quantity' => 3]], $owner->id);

    $this->actingAs($owner)->get(route('cash-register.operations'))->assertOk()
        ->assertSee('№'.$sale->id)->assertSee(route('cash-register.refund', $sale), false);
    $this->post(route('cash-register.refund', $sale))->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Возврат проведён: 2 100,00 KZT выданы из кассы.');

    $refund = CashTransaction::query()->where('refund_of_id', $sale->id)->sole();
    expect($refund->type)->toBe('expense')->and($refund->amount)->toBe('2100.00')
        ->and($cola->refresh()->quantity)->toBe(10)
        ->and(StockMovement::query()->where('type', StockMovement::TYPE_RECEIPT)->sole()->quantity)->toBe(3)
        ->and($cash->totals($shift)['balance'])->toBe(1000.0)
        ->and(ActivityLog::query()->where('event', 'cash.refund')->exists())->toBeTrue();

    // Once only; the report counts the refund and no longer the goods.
    $this->post(route('cash-register.refund', $sale))->assertSessionHasErrors('refund');
    $this->get(route('cash-register.receipt', $sale))->assertOk()->assertSee('Возвращено');
    $this->get(route('cash-register.x-report'))->assertOk()
        ->assertSee('За смену товары не продавались.')->assertSeeInOrder(['Возвраты (расход)', '1', '− 2 100,00 KZT']);
});

test('a refund of a top-up takes the amount and its bonus back from the customer balance', function () {
    $owner = User::factory()->create();
    $customer = User::factory()->create();
    $club = Club::query()->findOrFail(app(CurrentClub::class)->id());
    $club->forceFill(['settings' => array_replace_recursive($club->settings ?? [], ['finances' => ['topup_bonus_enabled' => true, 'topup_bonus_levels' => [['amount' => 1000, 'percent' => 10]]]])])->save();
    app(CurrentClub::class)->set($club->fresh());
    $cash = app(CashRegisterService::class);
    $cash->open(0, $owner->id);
    $topUp = $cash->topUpBalance($customer->id, 2000, 'balance', 'cash', $owner->id);
    expect(MemberBalance::query()->where('user_id', $customer->id)->sole()->bonus_balance)->toBe('200.00');

    // Spent: the balance is below the refund.
    MemberBalance::query()->where('user_id', $customer->id)->update(['balance' => 1500]);
    $this->actingAs($owner)->post(route('cash-register.refund', $topUp))->assertSessionHasErrors('refund');

    MemberBalance::query()->where('user_id', $customer->id)->update(['balance' => 2500]);
    $this->post(route('cash-register.refund', $topUp))->assertSessionHasNoErrors();
    $balance = MemberBalance::query()->where('user_id', $customer->id)->sole();
    expect($balance->balance)->toBe('500.00')->and($balance->bonus_balance)->toBe('0.00');
    expect(app(GuestHistory::class)->page($customer)['data'][0])
        ->toMatchArray(['title' => 'Возврат пополнения на кассе', 'amount' => '-2000.00', 'bonus_amount' => '-200.00']);
});

test('cashiers refund only with the right from the owner; other income is not refunded', function () {
    $owner = User::factory()->create();
    $cash = app(CashRegisterService::class);
    $cash->open(5000, $owner->id);
    $cola = Product::factory()->create(['price' => 100, 'quantity' => 5]);
    $sale = $cash->sale([['product_id' => $cola->id, 'quantity' => 1]], $owner->id);
    $other = $cash->transact(['type' => 'income', 'amount' => 300, 'description' => 'Разное'], $owner->id);

    $cashier = refundCashier();
    $this->actingAs($cashier)->get(route('cash-register.operations'))->assertOk()
        ->assertSee('Нет права на возвраты. Его даёт владелец клуба в «Настройки → Права».')->assertDontSee(route('cash-register.refund', $sale), false);
    $this->post(route('cash-register.refund', $sale))->assertForbidden();

    giveRefunds($cashier, true);
    $this->get(route('cash-register.operations'))->assertOk()->assertSee(route('cash-register.refund', $sale), false)->assertDontSee(route('cash-register.refund', $other), false);
    $this->post(route('cash-register.refund', $other))->assertSessionHasErrors('refund');
    $this->post(route('cash-register.refund', $sale))->assertSessionHasNoErrors();
    expect(CashTransaction::query()->where('refund_of_id', $sale->id)->value('user_id'))->toBe($cashier->id);
});
