<?php

use App\Models\ActivityLog;
use App\Models\CashShift;
use App\Models\ClubComputer;
use App\Models\ClubZone;
use App\Models\Tariff;
use App\Models\User;
use App\Services\CurrentClub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function editableTariff(array $attributes = []): Tariff
{
    return Tariff::query()->create($attributes + [
        'name' => 'Вечер', 'duration_minutes' => 180, 'price' => 1500, 'currency' => 'KZT',
        'payment_method' => 'main_or_bonus', 'discount_enabled' => true, 'is_active' => true,
        'usage_limit_enabled' => true, 'usage_limit_minutes' => 30 * 1440, 'quantity_limit_enabled' => false,
        'validity_periods' => [['start_day' => 'fri', 'start_time' => '18:00', 'end_day' => 'sun', 'end_time' => '23:59']],
    ]);
}

function tariffForm(array $overrides = []): array
{
    return $overrides + [
        'payment_method' => 'main_balance_only', 'name' => 'Вечер+', 'duration_value' => 4, 'duration_unit' => 'hours',
        'price' => '1800', 'discount_enabled' => '0',
        'usage_limit_enabled' => '0', 'quantity_limit_enabled' => '1', 'quantity_limit' => 50,
        'validity_periods' => [['start_day' => 'mon', 'start_time' => '00:00', 'end_day' => 'sun', 'end_time' => '23:59']],
        'is_active' => '0',
    ];
}

test('the edit form opens filled in with the tariff', function () {
    $this->actingAs(User::factory()->create());
    $tariff = editableTariff();

    $this->get(route('tariffs.index'))->assertOk()->assertSee(route('tariffs.edit', $tariff), false);
    $this->get(route('tariffs.edit', $tariff))->assertOk()
        ->assertSee(__('Изменить тариф'))
        ->assertSee('action="'.route('tariffs.update', $tariff).'"', false)
        ->assertSee('name="_method" value="PATCH"', false)
        ->assertSee('value="Вечер"', false)
        // 180 minutes → 3 hours, 30 days of usage limit, Friday 18:00 period.
        ->assertSee('name="duration_value" type="number" value="3"', false)
        ->assertSee('value="30"', false)
        ->assertSee('value="days" selected', false)
        ->assertSee('value="18:00"', false);
});

test('the manager changes a tariff; the name stays unique in the club', function () {
    $this->actingAs(User::factory()->create());
    $tariff = editableTariff();
    $zone = ClubZone::query()->create(['name' => 'VIP']);
    ClubComputer::query()->create(['club_zone_id' => $zone->id, 'number' => 1]);
    editableTariff(['name' => 'Ночь']);

    $this->patch(route('tariffs.update', $tariff), tariffForm(['name' => 'Ночь']))->assertSessionHasErrors('name');
    // Its own name is fine.
    $this->patch(route('tariffs.update', $tariff), tariffForm(['name' => 'Вечер', 'zone_ids' => [$zone->id]]))->assertRedirect(route('tariffs.index'))->assertSessionHasNoErrors();

    $tariff->refresh();
    expect($tariff)
        ->duration_minutes->toBe(240)
        ->price->toBe('1800.00')
        ->payment_method->toBe('main_balance_only')
        ->usage_limit_minutes->toBeNull()
        ->quantity_limit->toBe(50)
        ->is_active->toBeFalse()
        ->and($tariff->zones->modelKeys())->toBe([$zone->id])
        ->and(ActivityLog::query()->where('event', 'tariff.updated')->sole()->details['changed'])->toBe(['price', 'duration_minutes', 'is_active', 'zones']);
});

test('a tariff is on sale only on its sale days and times: the agent, the cash register and purchases follow it', function () {
    // Wednesday 12:00 in the club.
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $club = app(CurrentClub::class);
    $this->travelTo(Carbon::parse('2026-10-28 12:00', $club->timezone()));
    $period = fn (string $from, string $fromTime, string $to, string $toTime) => [['start_day' => $from, 'start_time' => $fromTime, 'end_day' => $to, 'end_time' => $toTime]];

    $this->post(route('tariffs.store'), tariffForm(['name' => 'Ночь', 'is_active' => '1', 'sale_periods' => $period('sat', '22:00', 'sun', '06:00')]))->assertSessionHasNoErrors();
    $this->post(route('tariffs.store'), tariffForm(['name' => 'Будни', 'is_active' => '1', 'sale_periods' => $period('mon', '09:00', 'fri', '18:00')]))->assertSessionHasNoErrors();
    $this->post(route('tariffs.store'), tariffForm(['name' => 'Всегда', 'is_active' => '1']))->assertSessionHasNoErrors();
    $night = Tariff::query()->where('name', 'Ночь')->sole();
    expect($night->sale_periods)->toBe($period('sat', '22:00', 'sun', '06:00'));
    $this->get(route('tariffs.edit', $night))->assertOk()
        ->assertSee('name="sale_periods[0][start_time]" type="time" value="22:00"', false)
        ->assertSee('Сейчас не продаётся: вне времени продажи');
    $this->post(route('tariffs.store'), tariffForm(['name' => 'Криво', 'sale_periods' => $period('xyz', '25:00', 'sun', '23:59')]))->assertSessionHasErrors(['sale_periods.0.start_day', 'sale_periods.0.start_time']);

    $headers = ['Authorization' => 'Bearer '.$owner->createToken('agent', ['club:'.$club->id()])->plainTextToken];
    $names = fn () => collect($this->getJson(route('api.tariffs.index'), $headers)->assertOk()->json('data'))->pluck('name')->sort()->values()->all();
    expect($names())->toBe(['Будни', 'Всегда']);

    $pc = ClubComputer::query()->create(['club_zone_id' => ClubZone::query()->create(['name' => 'Hall'])->id, 'number' => 1]);
    $this->get(route('club-map.index'))->assertOk()->assertDontSee('value="tariff:'.$night->id.'"', false);
    CashShift::query()->forceCreate(['opened_by' => $owner->id, 'status' => 'open', 'open_marker' => true, 'opening_balance' => 0, 'opened_at' => now()]);
    $this->postJson(route('club-computers.guest-session'), ['computer_ids' => [$pc->id], 'tariff_id' => $night->id, 'payment_method' => 'cash'])
        ->assertUnprocessable()->assertJsonValidationErrors('tariff_id');

    // Saturday 23:00: the night tariff is on sale, the weekday one is not.
    $this->travelTo(Carbon::parse('2026-10-31 23:00', $club->timezone()));
    expect($names())->toBe(['Всегда', 'Ночь']);
});

test('the tariff list is filtered by zone: its own tariffs and those of every zone', function () {
    $this->actingAs(User::factory()->create());
    $hall = ClubZone::query()->create(['name' => 'Зал']);
    $vip = ClubZone::query()->create(['name' => 'VIP']);
    foreach ([$hall, $vip] as $number => $zone) {
        ClubComputer::query()->create(['club_zone_id' => $zone->id, 'number' => $number + 1]);
    }
    editableTariff(['name' => 'Зальный'])->zones()->syncWithPivotValues([$hall->id], ['club_id' => $hall->club_id]);
    editableTariff(['name' => 'Випный'])->zones()->syncWithPivotValues([$vip->id], ['club_id' => $hall->club_id]);
    editableTariff(['name' => 'Общий']);

    $this->get(route('tariffs.index'))->assertOk()->assertSee('Зальный')->assertSee('Випный')->assertSee('Общий');
    $this->get(route('tariffs.index', ['zone' => $vip->id]))->assertOk()
        ->assertSee('<option value="'.$vip->id.'" selected>VIP</option>', false)
        ->assertSee('Випный')->assertSee('Общий')->assertDontSee('Зальный');
});

test('period rows saved until Monday by mistake end on their own day again', function () {
    $this->actingAs(User::factory()->create());
    $day = editableTariff(['name' => 'День', 'validity_periods' => [
        ['start_day' => 'mon', 'start_time' => '10:00', 'end_day' => 'mon', 'end_time' => '17:00'],
        ['start_day' => 'thu', 'start_time' => '10:00', 'end_day' => 'mon', 'end_time' => '17:00'],
        ['start_day' => 'sun', 'start_time' => '22:00', 'end_day' => 'mon', 'end_time' => '06:00'],
        ['start_day' => 'fri', 'start_time' => '23:00', 'end_day' => 'mon', 'end_time' => '06:00'],
    ]]);
    $week = editableTariff(['name' => 'Неделя', 'validity_periods' => [['start_day' => 'mon', 'start_time' => '00:00', 'end_day' => 'sun', 'end_time' => '23:59']]]);

    (require database_path('migrations/2026_10_27_100000_fix_tariff_periods_ending_on_monday.php'))->up();

    expect(collect($day->fresh()->validity_periods)->pluck('end_day')->all())->toBe(['mon', 'thu', 'mon', 'sat'])
        ->and($week->fresh()->validity_periods[0]['end_day'])->toBe('sun');
});

test('the cash register does not start a tariff outside its validity period', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $club = app(CurrentClub::class);
    $this->travelTo(Carbon::parse('2026-10-28 23:00', $club->timezone()));
    $day = editableTariff(['name' => 'День', 'validity_periods' => [['start_day' => 'wed', 'start_time' => '10:00', 'end_day' => 'wed', 'end_time' => '17:00']]]);
    $pc = ClubComputer::query()->create(['club_zone_id' => ClubZone::query()->create(['name' => 'Hall'])->id, 'number' => 1]);
    CashShift::query()->forceCreate(['opened_by' => $owner->id, 'status' => 'open', 'open_marker' => true, 'opening_balance' => 0, 'opened_at' => now()]);

    $this->get(route('club-map.index'))->assertOk()->assertDontSee('value="tariff:'.$day->id.'"', false);
    $this->postJson(route('club-computers.guest-session'), ['computer_ids' => [$pc->id], 'tariff_id' => $day->id, 'payment_method' => 'cash'])
        ->assertUnprocessable()->assertJsonValidationErrors('tariff_id');
});

test('the tariff form has no color and no club name', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('tariffs.index'))->assertOk()
        ->assertDontSee('name="color"', false)->assertDontSee('name="club_name"', false);
});
