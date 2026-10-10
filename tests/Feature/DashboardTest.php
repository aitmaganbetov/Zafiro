<?php

use App\Models\CashShift;
use App\Models\ClubComputer;
use App\Models\ClubZone;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('dashboard displays live cash, inventory, zone, and computer data', function () {
    $user = User::factory()->create();
    $shift = CashShift::query()->create([
        'opened_by' => $user->id,
        'status' => CashShift::STATUS_OPEN,
        'open_marker' => true,
        'opening_balance' => 0,
        'opened_at' => now(),
    ]);
    $shift->transactions()->create([
        'user_id' => $user->id,
        'type' => 'income',
        'amount' => 3200,
        'description' => 'Продажа сегодня',
    ]);
    $shift->transactions()->create([
        'user_id' => $user->id,
        'type' => 'expense',
        'amount' => 700,
        'description' => 'Расход сегодня',
    ]);
    $yesterdayTransaction = $shift->transactions()->create([
        'user_id' => $user->id,
        'type' => 'income',
        'amount' => 1000,
        'description' => 'Продажа вчера',
    ]);
    $yesterdayTransaction->created_at = now()->subDay();
    $yesterdayTransaction->saveQuietly();

    $zone = ClubZone::query()->create(['name' => 'Основной зал']);
    ClubComputer::query()->create([
        'club_zone_id' => $zone->id,
        'number' => 1,
        'status' => 'online',
        'last_seen_at' => now(),
    ]);
    ClubComputer::query()->create([
        'club_zone_id' => $zone->id,
        'number' => 2,
        'is_reserved' => true,
    ]);
    Product::factory()->create(['quantity' => 2]);
    Product::factory()->create(['quantity' => 20]);
    Storage::fake();
    Storage::put('settings.json', json_encode(['finances' => ['currency' => 'RUB']]));

    $this->actingAs($user)->get(route('dashboard'))
        ->assertSuccessful()
        ->assertViewHas('todayIncome', 3200.0)
        ->assertViewHas('todayExpenses', 700.0)
        ->assertViewHas('todayTransactionCount', 2)
        ->assertViewHas('productCount', 2)
        ->assertViewHas('lowStockCount', 1)
        ->assertViewHas('zoneCount', 1)
        ->assertViewHas('computerCount', 2)
        ->assertViewHas('onlineComputerCount', 1)
        ->assertViewHas('reservedComputerCount', 1)
        ->assertViewHas('chartIncome', fn (array $values): bool => $values[5] === 1000.0 && $values[6] === 3200.0)
        ->assertSee('3 200 RUB')
        ->assertSee('Продажа сегодня')
        ->assertSee('Расход сегодня');
});
