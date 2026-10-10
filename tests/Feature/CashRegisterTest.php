<?php

use App\Models\CashShift;
use App\Models\CashTransaction;
use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('cash shift can be opened and only one can remain open', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->post('/cash-register/shifts', ['opening_balance' => 10000])->assertRedirect();
    expect(CashShift::query()->where('status', 'open')->count())->toBe(1);
    $this->actingAs($user)->post('/cash-register/shifts', ['opening_balance' => 5000])->assertSessionHasErrors('opening_balance');
});

test('cash operations are included in X report', function () {
    $user = User::factory()->create();
    CashShift::create(['opened_by'=>$user->id,'status'=>'open','open_marker'=>true,'opening_balance'=>10000,'opened_at'=>now()]);
    $this->actingAs($user)->post('/cash-register/transactions', ['type'=>'income','amount'=>3000,'description'=>'Продажа'])->assertRedirect();
    $this->actingAs($user)->post('/cash-register/transactions', ['type'=>'expense','amount'=>1000,'description'=>'Расход'])->assertRedirect();
    expect(CashTransaction::count())->toBe(2);
    $this->actingAs($user)->get('/cash-register/reports/x')->assertSuccessful()->assertSee('12 000,00 KZT');
});

test('Z report must be printed before the shift can be closed', function () {
    $user = User::factory()->create();
    $shift = CashShift::create(['opened_by'=>$user->id,'status'=>'open','open_marker'=>true,'opening_balance'=>5000,'opened_at'=>now()]);
    $shift->transactions()->create(['user_id'=>$user->id,'type'=>'income','amount'=>2000,'description'=>'Продажа']);

    $this->actingAs($user)->post("/cash-register/shifts/{$shift->id}/close")
        ->assertRedirect(route('cash-register.z-report', $shift))
        ->assertSessionHasErrors('shift');
    expect($shift->refresh()->status)->toBe('open');

    $this->actingAs($user)->get("/cash-register/shifts/{$shift->id}/z-report")
        ->assertSuccessful()
        ->assertSee('Z-отчёт');

    $this->withSession(["z_report_printed.{$shift->id}" => true])
        ->post("/cash-register/shifts/{$shift->id}/close")
        ->assertRedirect(route('cash-register.z-report', $shift));
    expect($shift->refresh()->status)->toBe('closed')->and($shift->closing_balance)->toBe('7000.00');
});

test('POS sale writes off products and records cash income', function () {
    $user = User::factory()->create();
    CashShift::create(['opened_by'=>$user->id,'status'=>'open','open_marker'=>true,'opening_balance'=>0,'opened_at'=>now()]);
    $product = Product::factory()->create(['price'=>750,'quantity'=>5]);

    $response = $this->actingAs($user)->post('/cash-register/sales', ['items'=>[
        ['product_id'=>$product->id,'quantity'=>2],
    ]]);

    $transaction = CashTransaction::query()->first();
    $response->assertRedirect(route('cash-register.receipt', $transaction))->assertSessionHas('status');
    $this->actingAs($user)->get(route('cash-register.receipt', $transaction))
        ->assertSuccessful()
        ->assertSee($product->name)
        ->assertSee('Распечатать чек');

    expect($product->refresh()->quantity)->toBe(3)
        ->and($transaction->amount)->toBe('1500.00')
        ->and($transaction->receipt_data['items'][0]['quantity'])->toBe(2)
        ->and(StockMovement::query()->first()->balance_after)->toBe(3);
});

test('POS page displays products when shift is open', function () {
    $user = User::factory()->create();
    CashShift::create(['opened_by'=>$user->id,'status'=>'open','open_marker'=>true,'opening_balance'=>0,'opened_at'=>now()]);
    Product::factory()->create(['name'=>'POS Test Product','quantity'=>2]);
    $this->actingAs($user)->get('/cash-register')
        ->assertSuccessful()
        ->assertSee('POS Test Product')
        ->assertSee('Оплатить')
        ->assertSee('data-pos-view="sale"', false)
        ->assertSee('data-pos-view="balance"', false)
        ->assertSee('Пополнение баланса')
        ->assertSee('role="combobox"', false)
        ->assertSee('Поиск по имени или телефону')
        ->assertDontSee('Стол 1')
        ->assertDontSee('pos-tables', false);
});

test('cashier can top up a users main or bonus balance from the POS', function () {
    $cashier = User::factory()->create();
    $customer = User::factory()->create([
        'name' => 'Клиент POS',
        'balance' => 100,
        'bonus_balance' => 25,
    ]);
    CashShift::query()->create([
        'opened_by' => $cashier->id,
        'status' => 'open',
        'open_marker' => true,
        'opening_balance' => 500,
        'opened_at' => now(),
    ]);

    $this->actingAs($cashier)->post(route('cash-register.balance-top-ups.store'), [
        'user_id' => $customer->id,
        'amount' => 750,
        'balance_type' => 'balance',
        'payment_method' => 'card',
    ])->assertRedirect()
        ->assertSessionHas('status', 'Баланс пользователя пополнен.');

    expect($customer->refresh()->balance)->toBe('850.00')
        ->and($customer->bonus_balance)->toBe('25.00');

    $transaction = CashTransaction::query()->where('description', 'like', '%Клиент POS%')->firstOrFail();
    expect($transaction->type)->toBe('income')
        ->and($transaction->amount)->toBe('750.00')
        ->and(ActivityLog::query()->where('event', 'user.balance_topped_up')->where('subject_id', $customer->id)->exists())->toBeTrue()
        ->and(ActivityLog::query()->where('event', 'cash.income')->where('subject_id', $transaction->id)->exists())->toBeTrue();
});

test('balance top-up validates user, amount, account, and payment method', function () {
    $cashier = User::factory()->create();
    CashShift::query()->create([
        'opened_by' => $cashier->id,
        'status' => 'open',
        'open_marker' => true,
        'opening_balance' => 0,
        'opened_at' => now(),
    ]);

    $this->actingAs($cashier)->post(route('cash-register.balance-top-ups.store'), [
        'user_id' => 999999,
        'amount' => 0,
        'balance_type' => 'unknown',
        'payment_method' => 'unknown',
    ])->assertSessionHasErrors(['user_id', 'amount', 'balance_type', 'payment_method']);

    expect(CashTransaction::query()->count())->toBe(0);
});
