<?php

use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('warehouse, cash, and POS operations are recorded in the activity log', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create(['name' => 'Кофе', 'quantity' => 5]);

    $this->actingAs($user)->post(route('warehouse.stock-movements.store'), [
        'product_id' => $product->id,
        'type' => StockMovement::TYPE_RECEIPT,
        'quantity' => 4,
        'note' => 'Поставка утром',
    ])->assertRedirect();

    $this->post(route('cash-register.shifts.open'), ['opening_balance' => 1000])->assertRedirect();
    $this->post(route('cash-register.transactions.store'), [
        'type' => 'income',
        'amount' => 500,
        'description' => 'Пополнение кассы',
    ])->assertRedirect();
    $this->post(route('cash-register.sales.store'), [
        'items' => [['product_id' => $product->id, 'quantity' => 2]],
    ])->assertRedirect();

    expect(ActivityLog::query()->pluck('event')->all())->toContain(
        'stock.received',
        'cash.shift_opened',
        'cash.income',
        'stock.written_off',
        'product.purchased',
    );

    $this->get(route('activity-logs.index'))
        ->assertSuccessful()
        ->assertSee('Логи действий')
        ->assertSee('Поставка утром')
        ->assertSee('Покупка товаров: Кофе × 2');
});

test('successful web login and logout are recorded with the actor', function () {
    $user = User::factory()->create(['password' => 'secret123']);

    $this->get(route('auth.show-login'))
        ->assertSuccessful()
        ->assertSee('>+7</span>', false);

    $this->post(route('auth.login'), [
        'phone' => substr($user->phone, 2),
        'password' => 'secret123',
    ])->assertRedirect(route('dashboard'));

    expect(ActivityLog::query()->where('event', 'auth.login')->where('user_id', $user->id)->exists())->toBeTrue();

    $this->post(route('auth.logout'))->assertRedirect('/');

    expect(ActivityLog::query()->where('event', 'auth.logout')->where('user_id', $user->id)->exists())->toBeTrue();
});

test('activity log filters by event, user, date range, and search', function () {
    $user = User::factory()->create(['name' => 'Складской оператор']);
    $otherUser = User::factory()->create(['name' => 'Кассир']);
    $matching = ActivityLog::query()->create([
        'event' => 'stock.received',
        'description' => 'Оприходование: Кофе, 8 шт.',
        'user_id' => $user->id,
        'details' => ['quantity' => 8],
        'ip_address' => '127.0.0.1',
    ]);
    ActivityLog::query()->create([
        'event' => 'cash.income',
        'description' => 'Кассовое поступление тест',
        'user_id' => $otherUser->id,
        'ip_address' => '127.0.0.1',
    ]);
    $oldLog = ActivityLog::query()->create([
        'event' => 'stock.received',
        'description' => 'Оприходование: Чай, 3 шт.',
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
    ]);
    $oldLog->created_at = now()->subDays(10);
    $oldLog->saveQuietly();

    $this->actingAs($user)->get(route('activity-logs.index', [
        'event' => 'stock.received',
        'user_id' => $user->id,
        'from' => now()->subDay()->toDateString(),
        'search' => 'Кофе',
    ]))->assertSuccessful()
        ->assertSee('Оприходование: Кофе, 8 шт.')
        ->assertDontSee('Кассовое поступление тест')
        ->assertDontSee('Оприходование: Чай, 3 шт.');

    expect($matching->user_id)->toBe($user->id);
});

test('activity log page is protected from guests', function () {
    $this->get(route('activity-logs.index'))->assertRedirect(route('auth.show-login'));
});

test('activity log entries always name the acting user and the client ip', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('cash-register.shifts.open'), ['opening_balance' => 100])->assertRedirect();

    $log = ActivityLog::query()->where('event', 'cash.shift_opened')->firstOrFail();
    expect($log->user_id)->toBe($user->id)
        ->and($log->ip_address)->toBe('127.0.0.1');

    expect(fn () => ActivityLog::query()->create(['event' => 'auth.login', 'description' => 'Без IP', 'user_id' => $user->id]))
        ->toThrow(QueryException::class);
});
