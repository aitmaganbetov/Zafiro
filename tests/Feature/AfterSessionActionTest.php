<?php

use App\Models\Club;
use App\Models\ClubComputer;
use App\Models\ClubZone;
use App\Services\ClubComputerSessions;
use App\Services\CurrentClub;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the agent heartbeat reports what to do with the PC after a session ends', function () {
    $zone = ClubZone::query()->create(['name' => 'Hall']);
    $computer = ClubComputer::query()->create(['club_zone_id' => $zone->id, 'number' => 1]);
    $token = app(ClubComputerSessions::class)->issueAgentToken($computer);
    $club = Club::query()->findOrFail(app(CurrentClub::class)->id());
    app(CurrentClub::class)->clear();

    // Until the club picks an action the PC is only locked, as before.
    $this->postJson(route('api.agent.heartbeat'), [], ['X-Agent-Token' => $token])->assertOk()
        ->assertJsonPath('club.after_session', ['action' => 'none', 'delay_seconds' => 30]);

    $club->forceFill(['settings' => ['club' => ['pc_action_after_session_end' => 'restart', 'pc_restart_after_session_end' => 1.5]]])->save();
    app(CurrentClub::class)->clear();

    $this->postJson(route('api.agent.heartbeat'), [], ['X-Agent-Token' => $token])->assertOk()
        ->assertJsonPath('club.after_session', ['action' => 'restart', 'delay_seconds' => 90]);
});
