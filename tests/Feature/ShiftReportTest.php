<?php

use App\Models\ClubComputer;
use App\Models\ClubZone;
use App\Models\Product;
use App\Models\User;
use App\Services\CashRegisterService;
use App\Services\GuestSessions;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the X and Z report show what the shift consists of: income by kind, goods sold and staff', function () {
    $cashier = User::factory()->create(['name' => 'Айгерим']);
    $customer = User::factory()->create(['name' => 'Арман']);
    $cash = app(CashRegisterService::class);
    $shift = $cash->open(1000, $cashier->id);
    $cola = Product::factory()->create(['name' => 'Coca Cola', 'price' => 700, 'quantity' => 20]);
    $chips = Product::factory()->create(['name' => 'Чипсы', 'price' => 500, 'quantity' => 20]);

    $cash->sale([['product_id' => $cola->id, 'quantity' => 3], ['product_id' => $chips->id, 'quantity' => 1]], $cashier->id);
    $cash->sale([['product_id' => $cola->id, 'quantity' => 2]], $cashier->id);
    $cash->topUpBalance($customer->id, 5000, 'balance', 'card', $cashier->id);
    $pc = ClubComputer::query()->create(['club_zone_id' => ClubZone::query()->create(['name' => 'VIP', 'price_per_hour' => 600])->id, 'number' => 1]);
    app(GuestSessions::class)->sell([$pc->id], null, 60, 'cash', $cashier->id);
    $cash->transact(['type' => 'expense', 'amount' => 300, 'description' => 'Вода для кулера'], $cashier->id);

    foreach ([route('cash-register.x-report'), route('cash-register.z-report', $shift)] as $url) {
        $this->actingAs($cashier)->get($url)->assertOk()
            ->assertSeeInOrder(['Приход по видам', 'Продажа товаров', '2', '4 000,00 KZT', 'Пополнение балансов', '1', '5 000,00 KZT', 'Гостевые сессии', '1', '600,00 KZT'])
            ->assertSeeInOrder(['Продано товаров', 'Coca Cola', '5', '3 500,00 KZT', 'Чипсы', '1', '500,00 KZT', 'Всего', '6', '4 000,00 KZT'])
            ->assertSeeInOrder(['Сотрудники', 'Айгерим', '5', '9 600,00 KZT', '300,00 KZT']);
    }
});

test('an empty shift says no goods were sold', function () {
    $cashier = User::factory()->create();
    $shift = app(CashRegisterService::class)->open(0, $cashier->id);

    $this->actingAs($cashier)->get(route('cash-register.z-report', $shift))->assertOk()->assertSee('За смену товары не продавались.');
});
