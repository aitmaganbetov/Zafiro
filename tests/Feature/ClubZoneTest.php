<?php

use App\Models\ClubComputer;
use App\Models\ClubZone;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('authenticated user can manage club map zones', function () {
    $user = User::factory()->create();
    $data = [
        'name' => 'Турнирный зал',
        'description' => 'Зона для соревнований',
        'capacity' => 12,
        'icon' => 'desktop',
        'theme' => 'blue',
        'status' => 'available',
        'is_featured' => true,
        'sort_order' => 15,
    ];

    $this->actingAs($user)->post(route('club-map.store'), $data)
        ->assertRedirect()
        ->assertSessionHas('status');

    $zone = ClubZone::query()->firstOrFail();
    $this->assertModelExists($zone);
    expect($zone->computers()->count())->toBe(0);
    $this->actingAs($user)->post(route('club-computers.store', $zone))
        ->assertRedirect()
        ->assertSessionHas('status', 'Компьютер добавлен в зону.');
    $this->post(route('club-computers.store', $zone))->assertRedirect();
    expect($zone->computers()->count())->toBe(2);
    $this->actingAs($user)->get(route('club-map.index'))
        ->assertSuccessful()
        ->assertSee('id="reserve-selected"', false)
        ->assertSee('Турнирный зал')
        ->assertSee('class="cm-status available">Свободно', false)
        ->assertSee('class="cm-zone-info"', false)
        ->assertSee('12 мест')
        ->assertSee('class="cm-computer offline"', false)
        ->assertSee('class="cm-computer-edit"', false)
        ->assertSee('id="computer-info-panel"', false)
        ->assertSee('id="computer-session-info"', false)
        ->assertSee('data-has-session="false"', false);

    $this->actingAs($user)->put(route('club-map.update', $zone), [...$data, 'name' => 'Главный зал', 'status' => 'busy'])
        ->assertRedirect()
        ->assertSessionHas('status');
    expect($zone->refresh()->name)->toBe('Главный зал')
        ->and($zone->status)->toBe('busy')
        ->and($zone->computers()->count())->toBe(2);

    $this->actingAs($user)->patchJson(route('club-map.layout', $zone), [
        'position_x' => 240,
        'position_y' => 160,
        'width' => 480,
        'height' => 260,
    ])->assertSuccessful()->assertJson(['saved' => true]);
    expect($zone->refresh()->position_x)->toBe(240)
        ->and($zone->position_y)->toBe(160)
        ->and($zone->width)->toBe(480)
        ->and($zone->height)->toBe(260);

    $otherZone = ClubZone::query()->create([
        'name' => 'Соседний зал',
        'position_x' => 813,
        'position_y' => 507,
        'width' => 300,
        'height' => 180,
    ]);
    $this->actingAs($user)->patchJson(route('club-map.layout', $zone), [
        'position_x' => 820,
        'position_y' => 520,
        'width' => 300,
        'height' => 180,
    ])->assertConflict()->assertJson(['message' => 'Карточки залов не могут пересекаться.']);
    expect($zone->refresh()->position_x)->toBe(240);

    $this->actingAs($user)->post(route('club-map.align'))->assertRedirect()->assertSessionHas('status');
    expect($zone->refresh()->position_x)->toBe(240)
        ->and($zone->position_y)->toBe(160)
        ->and($otherZone->refresh()->position_x)->toBe(820)
        ->and($otherZone->position_y)->toBe(500);

    $computer = ClubComputer::query()->firstOrFail();
    expect($computer->currentStatus())->toBe('offline');
    $this->actingAs($user)->patchJson(route('club-computers.layout', $computer), [
        'position_x' => 125,
        'position_y' => 75,
    ])->assertSuccessful()->assertJson(['saved' => true]);
    expect($computer->refresh()->position_x)->toBe(125)
        ->and($computer->position_y)->toBe(75);

    $secondComputer = ClubComputer::query()->whereKeyNot($computer->id)->firstOrFail();
    $this->actingAs($user)->patchJson(route('club-computers.layout', $secondComputer), [
        'position_x' => 125,
        'position_y' => 75,
    ])->assertConflict()->assertJson(['message' => 'Эта ячейка уже занята другим компьютером.']);
    expect($secondComputer->refresh()->position_x)->not->toBe(125);

    $this->postJson(route('api.club-computers.heartbeat', $computer))
        ->assertSuccessful()
        ->assertJson(['status' => 'online']);

    $this->actingAs($user)->postJson(route('club-computers.control'), [
        'computer_ids' => [$computer->id, $secondComputer->id],
        'action' => 'power_off',
    ])->assertSuccessful()->assertJson(['updated' => 2, 'status' => 'offline']);
    expect($computer->refresh()->status)->toBe('offline')
        ->and($computer->currentStatus())->toBe('offline')
        ->and($secondComputer->refresh()->status)->toBe('offline')
        ->and($secondComputer->currentStatus())->toBe('offline');

    $this->actingAs($user)->postJson(route('club-computers.control'), [
        'computer_ids' => [$computer->id, $secondComputer->id],
        'action' => 'reserve',
    ])->assertSuccessful()->assertJson(['reserved' => true, 'updated' => 2]);
    expect($computer->refresh()->is_reserved)->toBeTrue()
        ->and($computer->currentStatus())->toBe('reserved');

    $this->postJson(route('api.club-computers.heartbeat', $computer))
        ->assertSuccessful()
        ->assertJson(['status' => 'reserved']);

    $this->postJson(route('club-computers.control'), [
        'computer_ids' => [$computer->id, $secondComputer->id],
        'action' => 'reserve',
    ])->assertSuccessful()->assertJson(['reserved' => false, 'updated' => 2]);
    expect($computer->refresh()->is_reserved)->toBeFalse()
        ->and($computer->currentStatus())->toBe('online');

    $this->actingAs($user)->delete(route('club-map.destroy', $zone))
        ->assertRedirect()
        ->assertSessionHas('status');
    $this->assertModelMissing($zone);
    $this->assertModelExists($otherZone);
});

test('computer details can be edited from the club map', function () {
    $user = User::factory()->create();
    $sourceZone = ClubZone::query()->create(['name' => 'Исходная зона', 'icon' => 'desktop']);
    $targetZone = ClubZone::query()->create(['name' => 'Новая зона', 'icon' => 'desktop', 'capacity' => 1]);
    $computer = ClubComputer::query()->create([
        'club_zone_id' => $sourceZone->id,
        'number' => 1,
        'device_name' => 'Agent PC 01',
        'position_x' => 5,
        'position_y' => 5,
    ]);
    $targetZone->computers()->create([
        'number' => 2,
        'position_x' => 5,
        'position_y' => 5,
    ]);

    $this->actingAs($user)->patch(route('club-computers.update', $computer), [
        'number' => 2,
        'display_name' => 'Главный игровой ПК',
        'club_zone_id' => $targetZone->id,
        'edit_mode' => 1,
    ])->assertSessionHasErrors('number');

    expect($computer->refresh()->club_zone_id)->toBe($sourceZone->id);

    $this->patch(route('club-computers.update', $computer), [
        'number' => 3,
        'display_name' => 'Главный игровой ПК',
        'club_zone_id' => $targetZone->id,
        'edit_mode' => 1,
    ])->assertSessionHasErrors('club_zone_id');

    $targetZone->update(['capacity' => 2]);

    $this->patch(route('club-computers.update', $computer), [
        'number' => 3,
        'display_name' => 'Главный игровой ПК',
        'club_zone_id' => $targetZone->id,
        'edit_mode' => 1,
    ])->assertRedirect()->assertSessionHas('status', 'Данные компьютера обновлены.');

    expect($computer->refresh()->number)->toBe(3)
        ->and($computer->display_name)->toBe('Главный игровой ПК')
        ->and($computer->displayName())->toBe('Главный игровой ПК')
        ->and($computer->club_zone_id)->toBe($targetZone->id)
        ->and($computer->position_x)->toBe(52)
        ->and($computer->position_y)->toBe(5);

    $computer->update(['device_name' => 'Agent renamed this device']);

    expect($computer->displayName())->toBe('Главный игровой ПК');

    $targetZone->computers()->create([
        'number' => 4,
        'display_name' => 'Занятое имя',
        'position_x' => 99,
        'position_y' => 47,
    ]);

    $this->from(route('club-map.index'))->followingRedirects()->patch(route('club-computers.update', $computer), [
        'computer_id' => $computer->id, // the edit form sends it to reopen the failed computer
        'number' => 3,
        'display_name' => 'Занятое имя',
        'club_zone_id' => $targetZone->id,
        'edit_mode' => 1,
    ])->assertSuccessful()
        ->assertSee('id="computer-form-errors"', false)
        ->assertSee('Это имя уже используется в клубе.')
        ->assertSee("document.getElementById('zone-modal').classList.remove('open')", false);
});

test('club zone input is validated', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('club-map.store'), [
        'name' => '',
        'icon' => 'script-tag',
        'theme' => 'unknown',
        'status' => 'unknown',
        'is_featured' => false,
        'sort_order' => -1,
    ])->assertSessionHasErrors(['name', 'icon', 'theme', 'status', 'sort_order']);

    expect(ClubZone::query()->count())->toBe(0);
});

test('saving a zone keeps the map editor enabled', function () {
    $user = User::factory()->create();
    $data = [
        'name' => '',
        'icon' => 'desktop',
        'theme' => 'green',
        'status' => 'available',
        'is_featured' => false,
        'sort_order' => 0,
        'edit_mode' => 1,
    ];

    $this->actingAs($user)->post(route('club-map.store'), $data)
        ->assertSessionHasErrors('name');

    $this->get(route('club-map.index'))
        ->assertSuccessful()
        ->assertSee('class="cm-page edit-mode"', false)
        ->assertSee('id="editor-toggle" checked', false);

    $data['name'] = 'Новая зона';
    $this->post(route('club-map.store'), $data)
        ->assertRedirect()
        ->assertSessionHas('edit_mode', true);

    $this->get(route('club-map.index'))
        ->assertSuccessful()
        ->assertSee('class="cm-page edit-mode"', false)
        ->assertSee('id="editor-toggle" checked', false);
});

test('couch zones render a sofa shape', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('club-map.store'), [
        'name' => 'Зона отдыха',
        'icon' => 'couch',
        'theme' => 'green',
        'status' => 'available',
        'is_featured' => false,
        'sort_order' => 0,
    ])->assertRedirect();

    $this->get(route('club-map.index'))
        ->assertSuccessful()
        ->assertSee('class="cm-zone green available sofa"', false)
        ->assertSee('cm-sofa-art', false)
        ->assertSee('cm-sofa-seat', false)
        ->assertDontSee('<h3>Зона отдыха</h3>', false)
        ->assertDontSee('class="cm-status available"', false)
        ->assertDontSee('class="cm-zone-info"', false)
        ->assertDontSee('class="cm-capacity"', false);

    $zone = ClubZone::query()->firstOrFail();
    $this->patchJson(route('club-map.layout', $zone), [
        'position_x' => $zone->position_x,
        'position_y' => $zone->position_y,
        'width' => 80,
        'height' => 60,
        'rotation' => 90,
    ])->assertSuccessful()->assertJson(['saved' => true]);

    expect($zone->refresh()->rotation)->toBe(90)
        ->and($zone->width)->toBe(80)
        ->and($zone->height)->toBe(60);
    $this->get(route('club-map.index'))
        ->assertSuccessful()
        ->assertSee('class="cm-zone green available sofa rotated-90"', false);
});

test('non-sofa zones without computers still render their icon', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('club-map.store'), [
        'name' => 'Игровая зона',
        'icon' => 'gamepad',
        'theme' => 'neutral',
        'status' => 'available',
        'is_featured' => false,
        'sort_order' => 0,
    ])->assertRedirect();

    $this->get(route('club-map.index'))
        ->assertSuccessful()
        ->assertSee('fas fa-gamepad', false)
        ->assertSee('class="cm-zone-info"', false)
        ->assertSee('<h3>Игровая зона</h3>', false)
        ->assertDontSee('class="cm-status available"', false)
        ->assertDontSee('class="cm-capacity"', false);
});

test('manual computer creation respects zone capacity', function () {
    $user = User::factory()->create();
    $zone = ClubZone::query()->create([
        'name' => 'Малый зал',
        'icon' => 'desktop',
        'capacity' => 1,
    ]);

    $this->actingAs($user)->post(route('club-computers.store', $zone))
        ->assertRedirect()
        ->assertSessionHas('status', 'Компьютер добавлен в зону.');
    $this->post(route('club-computers.store', $zone))
        ->assertRedirect()
        ->assertSessionHas('status', 'Достигнута вместимость зоны.');

    expect($zone->computers()->count())->toBe(1);
});

test('tariffs can be attached to and changed on a zone', function () {
    $user = User::factory()->create();
    $firstTariff = Tariff::query()->create([
        'name' => 'Часовой',
        'duration_minutes' => 60,
        'price' => 1000,
        'is_active' => true,
    ]);
    $secondTariff = Tariff::query()->create([
        'name' => 'Дневной',
        'duration_minutes' => 480,
        'price' => 5000,
        'is_active' => true,
    ]);
    $data = [
        'name' => 'Главный зал',
        'icon' => 'desktop',
        'theme' => 'green',
        'status' => 'available',
        'is_featured' => false,
        'sort_order' => 0,
    ];

    $this->actingAs($user)->post(route('club-map.store'), [...$data, 'tariff_ids' => [$firstTariff->id]])
        ->assertRedirect();
    $zone = ClubZone::query()->firstOrFail();
    expect($zone->tariffs()->pluck('tariffs.id')->all())->toBe([$firstTariff->id]);

    $this->put(route('club-map.update', $zone), [...$data, 'tariff_ids' => [$secondTariff->id]])
        ->assertRedirect();
    expect($zone->refresh()->tariffs()->pluck('tariffs.id')->all())->toBe([$secondTariff->id]);

    $this->get(route('club-map.index'))
        ->assertSuccessful()
        ->assertSee('data-tariff-ids="'.$secondTariff->id.'"', false);
});
