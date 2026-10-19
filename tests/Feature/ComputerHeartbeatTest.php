<?php

use App\Models\ClubComputer;
use App\Models\ClubZone;
use App\Models\User;
use App\Services\ClubComputerSessions;
use App\Services\CurrentClub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

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
    app(CurrentClub::class)->clear();

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

test('a heartbeat that changes nothing is not written while last_seen_at is fresh', function () {
    $pc = ClubComputer::query()->create(['club_zone_id' => ClubZone::query()->create(['name' => 'Hall'])->id, 'number' => 3]);
    $token = app(ClubComputerSessions::class)->issueAgentToken($pc);
    app(CurrentClub::class)->clear();
    $beat = fn (array $data = ['state' => 'idle']) => test()->postJson(route('api.agent.heartbeat'), $data, ['X-Agent-Token' => $token])->assertOk();
    $writes = function (callable $request): int {
        $updates = 0;
        DB::listen(function ($query) use (&$updates): void {
            $updates += str_starts_with(strtolower($query->sql), 'update "club_computers"') ? 1 : 0;
        });
        $request();
        DB::flushQueryLog();

        return $updates;
    };
    $this->freezeTime();

    expect($writes(fn () => $beat()))->toBe(1);
    $seen = $pc->fresh()->last_seen_at;

    // The sign-in screen asks again a few seconds later: nothing changed, nothing is written.
    $this->travel(5)->seconds();
    expect($writes(fn () => $beat()))->toBe(0)
        ->and($pc->fresh()->last_seen_at->equalTo($seen))->toBeTrue();

    // A change is written at once.
    $this->travel(1)->seconds();
    expect($writes(fn () => $beat(['state' => 'locked'])))->toBe(1)
        ->and($pc->fresh()->agent_state)->toBe('locked');

    // An unchanged heartbeat refreshes last_seen_at once it is older than the limit, so the PC stays online.
    $this->travel(ClubComputerSessions::SEEN_WRITE_SECONDS + 1)->seconds();
    expect($writes(fn () => $beat(['state' => 'locked'])))->toBe(1)
        ->and($pc->fresh()->last_seen_at->getTimestamp())->toBe(now()->getTimestamp())
        ->and($pc->fresh()->connectionStatus())->toBe('online');
});
