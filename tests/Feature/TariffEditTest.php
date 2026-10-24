<?php

use App\Models\ActivityLog;
use App\Models\CashShift;
use App\Models\ClubComputer;
use App\Models\ClubZone;
use App\Models\Tariff;
use App\Models\User;
use App\Services\CurrentClub;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function editableTariff(array $attributes = []): Tariff
{
    return Tariff::query()->create($attributes + [
        'name' => 'Вечер', 'duration_minutes' => 180, 'price' => 1500, 'currency' => 'KZT',
        'payment_method' => 'main_or_bonus', 'discount_enabled' => true, 'is_active' => true, 'color' => '#2563eb', 'club_name' => 'Zafiro',
        'usage_limit_enabled' => true, 'usage_limit_minutes' => 30 * 1440, 'quantity_limit_enabled' => false,
        'validity_periods' => [['start_day' => 'fri', 'start_time' => '18:00', 'end_day' => 'sun', 'end_time' => '23:59']],
    ]);
}

function tariffForm(array $overrides = []): array
{
    return $overrides + [
        'payment_method' => 'main_balance_only', 'name' => 'Вечер+', 'duration_value' => 4, 'duration_unit' => 'hours',
        'price' => '1800', 'discount_enabled' => '0', 'color' => '#ff0169', 'club_name' => 'Zafiro',
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

test('a tariff is on sale only within its sale time: the agent, the cash register and purchases follow it', function () {
    $this->freezeTime();
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $club = app(CurrentClub::class);
    $local = fn (string $modify) => now($club->timezone())->modify($modify)->format('Y-m-d\TH:i');

    // Entered in the club's local time, shown back the same.
    $this->post(route('tariffs.store'), tariffForm(['name' => 'Скоро', 'is_active' => '1', 'sale_starts_at' => $local('+1 day')]))->assertSessionHasNoErrors();
    $this->post(route('tariffs.store'), tariffForm(['name' => 'Сейчас', 'is_active' => '1', 'sale_starts_at' => $local('-1 day'), 'sale_ends_at' => $local('+1 day')]))->assertSessionHasNoErrors();
    $this->post(route('tariffs.store'), tariffForm(['name' => 'Прошёл', 'is_active' => '1', 'sale_ends_at' => $local('-1 hour')]))->assertSessionHasNoErrors();
    $this->post(route('tariffs.store'), tariffForm(['name' => 'Криво', 'sale_starts_at' => $local('+2 days'), 'sale_ends_at' => $local('+1 day')]))->assertSessionHasErrors('sale_ends_at');
    $soon = Tariff::query()->where('name', 'Скоро')->sole();
    expect($soon->sale_starts_at->format('Y-m-d\TH:i'))->toBe($local('+1 day'));
    $this->get(route('tariffs.edit', $soon))->assertOk()->assertSee('value="'.$local('+1 day').'"', false)->assertSee('Продажа с ');

    $headers = ['Authorization' => 'Bearer '.$owner->createToken('agent', ['club:'.$club->id()])->plainTextToken];
    $names = collect($this->getJson(route('api.tariffs.index'), $headers)->assertOk()->json('data'))->pluck('name')->all();
    expect($names)->toBe(['Сейчас']);

    $pc = ClubComputer::query()->create(['club_zone_id' => ClubZone::query()->create(['name' => 'Hall'])->id, 'number' => 1]);
    $this->get(route('club-map.index'))->assertOk()->assertSee('value="tariff:'.Tariff::query()->where('name', 'Сейчас')->value('id').'"', false)
        ->assertDontSee('value="tariff:'.$soon->id.'"', false);
    CashShift::query()->forceCreate(['opened_by' => $owner->id, 'status' => 'open', 'open_marker' => true, 'opening_balance' => 0, 'opened_at' => now()]);
    $this->postJson(route('club-computers.guest-session'), ['computer_ids' => [$pc->id], 'tariff_id' => $soon->id, 'payment_method' => 'cash'])
        ->assertUnprocessable()->assertJsonValidationErrors('tariff_id');
});
