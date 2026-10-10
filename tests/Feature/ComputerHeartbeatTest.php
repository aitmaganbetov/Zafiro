<?php

use App\Models\ClubComputer;
use App\Models\ClubZone;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('authenticated agent heartbeat marks computer online and returns polling interval', function () {
    $user = User::factory()->create();
    $zone = ClubZone::query()->create(['name' => 'Основной зал']);
    $computer = ClubComputer::query()->create([
        'club_zone_id' => $zone->id,
        'number' => 1,
        'status' => 'offline',
    ]);
    $token = $user->createToken('wpf-agent')->plainTextToken;

    $this->withToken($token)
        ->postJson(route('api.club-computers.heartbeat', $computer))
        ->assertSuccessful()
        ->assertJsonPath('computer_id', $computer->id)
        ->assertJsonPath('status', 'online')
        ->assertJsonPath('heartbeat_interval_seconds', 30);

    expect($computer->refresh()->currentStatus())->toBe('online')
        ->and($computer->last_seen_at)->not->toBeNull();
});

test('computers with stale heartbeats are reported offline', function () {
    $zone = ClubZone::query()->create(['name' => 'Основной зал']);
    $computer = ClubComputer::query()->create([
        'club_zone_id' => $zone->id,
        'number' => 1,
        'status' => 'online',
        'last_seen_at' => now()->subSeconds(ClubComputer::HEARTBEAT_TIMEOUT_SECONDS + 1),
    ]);

    expect($computer->currentStatus())->toBe('offline');
});

test('computer heartbeat requires authentication', function () {
    $zone = ClubZone::query()->create(['name' => 'Основной зал']);
    $computer = ClubComputer::query()->create([
        'club_zone_id' => $zone->id,
        'number' => 1,
    ]);

    $this->postJson(route('api.club-computers.heartbeat', $computer))->assertUnauthorized();
});