<?php

use App\Models\CashTransaction;
use App\Models\ClubMembership;
use App\Models\ClubService;
use App\Models\Product;
use App\Models\User;
use App\Services\CashRegisterService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a manager keeps the services; a cashier sells them with goods in one receipt, without stock', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner)->post(route('services.store'), ['name' => 'PS5 — 1 час', 'price' => '1500', 'unit' => 'час'])->assertRedirect(route('services.index'));
    $ps = ClubService::query()->sole();
    $this->post(route('services.store'), ['name' => 'Старое', 'price' => '10'])->assertRedirect();
    $old = ClubService::query()->where('name', 'Старое')->sole();
    $this->patch(route('services.update', $old), ['name' => 'Старое', 'price' => '10', 'is_active' => '0'])->assertRedirect();

    $cashier = User::factory()->create();
    ClubMembership::query()->where('user_id', $cashier->id)->update(['role' => 'cashier']);
    app(CashRegisterService::class)->open(0, $cashier->id);
    $cola = Product::factory()->create(['name' => 'Cola', 'price' => 500, 'quantity' => 10]);

    $this->actingAs($cashier)->get(route('cash-register.index'))->assertOk()
        ->assertSee('data-key="s'.$ps->id.'"', false)->assertDontSee('data-key="s'.$old->id.'"', false);
    $this->post(route('cash-register.sales.store'), ['items' => [['service_id' => $ps->id, 'quantity' => 2], ['product_id' => $cola->id, 'quantity' => 1]]])->assertRedirect();

    $sale = CashTransaction::query()->sole();
    expect($sale->amount)->toBe('3500.00')
        ->and(collect($sale->receipt_data['items'])->pluck('name')->all())->toEqualCanonicalizing(['PS5 — 1 час', 'Cola'])
        ->and($cola->fresh()->quantity)->toBe(9);
    $this->get(route('cash-register.x-report'))->assertOk()->assertSeeInOrder(['Продано товаров', 'PS5 — 1 час', '2', '3 000,00 KZT']);

    // An inactive service is not sold; a cashier does not change the services.
    $this->post(route('cash-register.sales.store'), ['items' => [['service_id' => $old->id, 'quantity' => 1]]])->assertSessionHasErrors('items.0.service_id');
    $this->post(route('services.store'), ['name' => 'X', 'price' => '1'])->assertForbidden();
});

test('refunding a sale with a service returns the money and the goods only', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $print = ClubService::query()->create(['name' => 'Распечатка', 'price' => 50, 'unit' => 'стр.']);
    $cola = Product::factory()->create(['name' => 'Cola', 'price' => 500, 'quantity' => 10]);
    $cash = app(CashRegisterService::class);
    $cash->open(1000, $owner->id);
    $sale = $cash->sale([['service_id' => $print->id, 'quantity' => 10], ['product_id' => $cola->id, 'quantity' => 2]], $owner->id);

    $this->post(route('cash-register.refund', $sale))->assertSessionHasNoErrors();
    expect($cola->fresh()->quantity)->toBe(10)
        ->and(CashTransaction::query()->where('refund_of_id', $sale->id)->value('amount'))->toBe('1500.00');
});
