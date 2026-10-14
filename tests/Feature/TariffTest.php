<?php

use App\Models\ClubComputer;
use App\Models\ClubMembership;
use App\Models\ClubZone;
use App\Models\MemberBalance;
use App\Models\Tariff;
use App\Models\User;
use App\Services\CurrentClub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('authenticated users can create and view tariffs', function () {
    $user = User::factory()->create();
    $zone = ClubZone::query()->create(['name' => 'Зал']);
    ClubComputer::query()->create(['club_zone_id' => $zone->id, 'number' => 1]);
    $emptyZone = ClubZone::query()->create(['name' => 'Пустая зона']);
    Storage::fake();
    Storage::put('settings.json', json_encode(['finances' => ['currency' => 'RUB']]));

    $this->actingAs($user)->get(route('tariffs.index'))
        ->assertSuccessful()
        ->assertSee('class="tariff-add-button"', false)
        ->assertSee('Добавить тариф')
        ->assertSee('<dialog id="tariff-dialog"', false)
        ->assertSee('Новый тариф')
        ->assertSee('class="tariff-duration-fields"', false)
        ->assertSee('Зоны и тарифы')
        ->assertSee('>Zafiro</strong>', false)
        ->assertSee('src="'.asset('images/logo.svg').'"', false)
        ->assertSee('images/logo.svg', false)
        ->assertSee('>Зоны</span>', false)
        ->assertSee('>Тарифы</span>', false)
        ->assertDontSee('name="currency"', false)
        ->assertSee('class="tariff-validity-period"', false)
        ->assertSeeInOrder(['data-add-validity-period', 'data-remove-period'], false)
        ->assertSee('value="'.$zone->id.'"', false)
        ->assertSee('Зал')
        ->assertDontSee('Пустая зона');

    $this->post(route('tariffs.store'), [
        'name' => 'Ночной',
        'description' => 'Сеанс на всю ночь',
        'duration_value' => 8,
        'duration_unit' => 'hours',
        'price' => 3500,
        'payment_method' => 'main_or_bonus',
        'discount_enabled' => 1,
        'color' => '#2563eb',
        'club_name' => 'Xanexe',
        'zone_ids' => [$zone->id],
        'usage_limit_enabled' => 1,
        'usage_limit_value' => 2,
        'usage_limit_unit' => 'hours',
        'quantity_limit_enabled' => 1,
        'quantity_limit' => 5,
        'validity_periods' => [
            ['start_day' => 'mon', 'start_time' => '09:00', 'end_day' => 'fri', 'end_time' => '18:00'],
            ['start_day' => 'sat', 'start_time' => '10:00', 'end_day' => 'sun', 'end_time' => '20:00'],
        ],
        'is_active' => 1,
    ])->assertRedirect(route('tariffs.index'))
        ->assertSessionHas('status', 'Тариф создан.');

    $tariff = Tariff::query()->firstOrFail();
    expect($tariff->duration_minutes)->toBe(480)
        ->and($tariff->price)->toBe('3500.00')
        ->and($tariff->currency)->toBe('RUB')
        ->and($tariff->is_active)->toBeTrue()
        ->and($tariff->payment_method)->toBe('main_or_bonus')
        ->and($tariff->discount_enabled)->toBeTrue()
        ->and($tariff->color)->toBe('#2563eb')
        ->and($tariff->club_name)->toBe('Xanexe')
        ->and($tariff->usage_limit_minutes)->toBe(120)
        ->and($tariff->quantity_limit)->toBe(5)
        ->and($tariff->validity_periods[0]['start_time'])->toBe('09:00')
        ->and($tariff->validity_periods[1]['end_day'])->toBe('sun')
        ->and($tariff->zones->modelKeys())->toBe([$zone->id]);

    $this->get(route('tariffs.index'))
        ->assertSuccessful()
        ->assertSee('Ночной')
        ->assertSee('480 мин.')
        ->assertSee('3 500,00 RUB');
});

test('tariff fields are validated', function () {
    $emptyZone = ClubZone::query()->create(['name' => 'Пустая зона']);

    $this->actingAs(User::factory()->create())
        ->post(route('tariffs.store'), [
            'name' => '',
            'duration_value' => 0,
            'duration_unit' => 'unknown',
            'price' => -1,
            'payment_method' => 'invalid',
            'discount_enabled' => 0,
            'color' => 'red',
            'club_name' => '',
            'zone_ids' => [$emptyZone->id],
            'usage_limit_enabled' => 1,
            'usage_limit_value' => 0,
            'usage_limit_unit' => 'unknown',
            'quantity_limit_enabled' => 1,
            'quantity_limit' => 0,
            'validity_periods' => [],
            'is_active' => 'invalid',
        ])->assertSessionHasErrors(['name', 'duration_value', 'duration_unit', 'price', 'payment_method', 'color', 'club_name', 'zone_ids.0', 'usage_limit_value', 'usage_limit_unit', 'quantity_limit', 'validity_periods', 'is_active']);

    expect(Tariff::query()->count())->toBe(0);
});

test('agent can list and purchase an eligible tariff for its active computer session', function () {
    $user = User::factory()->create();
    $membership = ClubMembership::query()->where('user_id', $user->id)->firstOrFail();
    $membership->update(['discount' => 10]);
    $token = $user->createToken('agent', ['club:'.$membership->club_id])->plainTextToken;
    $zone = ClubZone::query()->create(['name' => 'Игровой зал']);
    $computer = ClubComputer::query()->create([
        'club_zone_id' => $zone->id,
        'number' => 7,
    ]);
    $tariff = Tariff::query()->create([
        'name' => 'Ночной',
        'duration_minutes' => 120,
        'price' => 100,
        'currency' => 'KZT',
        'payment_method' => 'main_or_bonus',
        'discount_enabled' => true,
        'usage_limit_enabled' => true,
        'usage_limit_minutes' => 90,
        'quantity_limit_enabled' => true,
        'quantity_limit' => 1,
        'is_active' => true,
    ]);
    $tariff->zones()->syncWithPivotValues([$zone->id], ['club_id' => $computer->club_id]);
    $balance = MemberBalance::query()->where('user_id', $user->id)->firstOrFail();
    $balance->update(['balance' => 80, 'bonus_balance' => 20]);
    app(CurrentClub::class)->clear();

    $this->getJson(route('api.tariffs.index'))->assertUnauthorized();
    $this->withToken($token)->getJson(route('api.tariffs.index'))
        ->assertSuccessful()
        ->assertJsonPath('data.0.id', (string) $tariff->id)
        ->assertJsonPath('data.0.price', '90.00')
        ->assertJsonPath('data.0.minutes', 90)
        ->assertJsonPath('data.0.currency', 'KZT');

    $frozenTime = now()->startOfSecond();
    Carbon::setTestNow($frozenTime);

    try {
        $purchaseResponse = $this->withToken($token)->postJson(route('api.tariffs.purchase', $tariff), [
            'computer_id' => $computer->id,
        ]);
        $purchaseResponse->assertSuccessful()
            ->assertJsonPath('success', true)
            ->assertJsonPath('new_balance', '0.00')
            ->assertJsonPath('new_bonus', '10.00')
            ->assertJsonPath('minutes_added', 90);

        $sessionId = $purchaseResponse->json('session_id');
        expect($balance->refresh()->balance)->toBe('0.00')
            ->and($balance->bonus_balance)->toBe('10.00')
            ->and($computer->refresh()->current_session_id)->toBe($sessionId)
            ->and($computer->current_session_user_id)->toBe($user->id)
            ->and($computer->refresh()->session_ends_at->utc()->toIso8601String())
            ->toBe($frozenTime->copy()->addMinutes(90)->toIso8601String());

        $this->withToken($token)->getJson(route('api.tariffs.index'))
            ->assertSuccessful()
            ->assertJsonCount(0, 'data');
    } finally {
        Carbon::setTestNow();
    }
});
