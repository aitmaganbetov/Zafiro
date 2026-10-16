<?php

use App\Models\Club;
use App\Models\ClubComputer;
use App\Models\ClubSubscription;
use App\Models\ClubZone;
use App\Models\User;
use App\Services\ClubComputerSessions;
use App\Services\CurrentClub;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const DISKLESS_UUID = '4C4C4544-0042-3510-8051-B4C04F4A3132';

function disklessPc(int $number, ?string $deviceId): ClubComputer
{
    $zone = ClubZone::query()->firstOrCreate(['name' => 'Hall']);
    $computer = ClubComputer::query()->create(['club_zone_id' => $zone->id, 'number' => $number]);
    $computer->forceFill(['device_id' => $deviceId])->save();

    return $computer;
}

function disklessClubKey(): string
{
    return Club::query()->findOrFail(app(CurrentClub::class)->id())->installation_key;
}

test('a registered PC gets its own token by the club key and its hardware UUID', function () {
    User::factory()->create();
    $computer = disklessPc(7, DISKLESS_UUID);
    $old = app(ClubComputerSessions::class)->issueAgentToken($computer);
    $key = disklessClubKey();
    app(CurrentClub::class)->clear();

    $response = $this->postJson(route('api.agent.enroll'), ['club_key' => $key, 'device_id' => strtolower(DISKLESS_UUID), 'device_name' => 'PC-07'])
        ->assertOk()
        ->assertJsonPath('computer_id', $computer->id)
        ->assertJsonPath('number', '7');
    $token = $response->json('agent_token');

    expect($token)->toMatch('/^zag_[A-Za-z0-9]{48}$/')
        ->and(app(ClubComputerSessions::class)->findByAgentToken($token)?->is($computer))->toBeTrue()
        ->and(app(ClubComputerSessions::class)->findByAgentToken($old))->toBeNull();
    $this->postJson(route('api.agent.heartbeat'), [], ['X-Agent-Token' => $token, 'X-Agent-Device' => DISKLESS_UUID])->assertOk();
});

test('an unknown PC with the club key is added to the club and gets a token', function () {
    User::factory()->create();
    disklessPc(1, DISKLESS_UUID);
    $key = disklessClubKey();
    app(CurrentClub::class)->clear();

    $token = $this->postJson(route('api.agent.enroll'), ['club_key' => $key, 'device_id' => 'OTHER-UUID', 'device_name' => 'PC-02'])
        ->assertOk()->json('agent_token');

    $computer = ClubComputer::query()->withoutGlobalScope('club')->where('device_id', 'OTHER-UUID')->firstOrFail();
    expect($computer->zone()->withoutGlobalScope('club')->value('name'))->toBe('Не назначена')
        ->and(app(ClubComputerSessions::class)->findByAgentToken($token)?->is($computer))->toBeTrue();

    // The same PC enrolls again without creating a second card.
    $this->postJson(route('api.agent.enroll'), ['club_key' => $key, 'device_id' => 'OTHER-UUID'])->assertOk();
    expect(ClubComputer::query()->withoutGlobalScope('club')->where('device_id', 'OTHER-UUID')->count())->toBe(1);
});

test('a wrong key or an inactive subscription get no token', function () {
    User::factory()->create();
    disklessPc(1, DISKLESS_UUID);
    $key = disklessClubKey();
    $clubId = app(CurrentClub::class)->id();
    app(CurrentClub::class)->clear();

    $this->postJson(route('api.agent.enroll'), ['club_key' => str_repeat('x', 64), 'device_id' => DISKLESS_UUID])
        ->assertUnauthorized()->assertJsonPath('code', 'invalid_club_key');
    $this->postJson(route('api.agent.enroll'), ['club_key' => $key])->assertUnprocessable();

    ClubSubscription::query()->withoutGlobalScope('club')->where('club_id', $clubId)->update(['status' => 'canceled']);
    $this->postJson(route('api.agent.enroll'), ['club_key' => $key, 'device_id' => DISKLESS_UUID])->assertStatus(402);
});

test('a PC of another club joins this club as a new card and keeps the other card', function () {
    User::factory()->create();
    $key = disklessClubKey();
    $other = Club::query()->create(['name' => 'Other', 'slug' => 'other-club']);
    app(CurrentClub::class)->set($other);
    disklessPc(1, DISKLESS_UUID);
    app(CurrentClub::class)->clear();

    $theirs = ClubComputer::query()->withoutGlobalScope('club')->where('club_id', $other->id)->firstOrFail();

    $this->postJson(route('api.agent.enroll'), ['club_key' => $key, 'device_id' => DISKLESS_UUID])->assertOk();

    expect(ClubComputer::query()->withoutGlobalScope('club')->where('device_id', DISKLESS_UUID)->pluck('club_id')->sort()->values()->all())
        ->toHaveCount(2)->and($theirs->fresh()->club_id)->toBe($other->id);
});

test('a token copied with a boot image does not work on another PC but stays valid on its own', function () {
    User::factory()->create();
    $master = disklessPc(1, DISKLESS_UUID);
    $token = app(ClubComputerSessions::class)->issueAgentToken($master);
    app(CurrentClub::class)->clear();

    $this->postJson(route('api.agent.heartbeat'), [], ['X-Agent-Token' => $token, 'X-Agent-Device' => 'CLONE-UUID'])
        ->assertUnauthorized()->assertJsonPath('code', 'device_mismatch');
    $this->postJson(route('api.agent.heartbeat'), [], ['X-Agent-Token' => $token, 'X-Agent-Device' => strtolower(DISKLESS_UUID)])->assertOk();
    // Agents that do not send their hardware UUID keep working as before.
    $this->postJson(route('api.agent.heartbeat'), [], ['X-Agent-Token' => $token])->assertOk();
});

test('enrollment attempts are rate limited per PC', function () {
    User::factory()->create();
    $key = disklessClubKey();
    app(CurrentClub::class)->clear();

    foreach (range(1, 5) as $attempt) {
        $this->postJson(route('api.agent.enroll'), ['club_key' => $key, 'device_id' => 'UNKNOWN'])->assertOk();
    }
    $this->postJson(route('api.agent.enroll'), ['club_key' => $key, 'device_id' => 'UNKNOWN'])->assertTooManyRequests();
    $this->postJson(route('api.agent.enroll'), ['club_key' => $key, 'device_id' => 'ANOTHER'])->assertOk();
});

test('a second PC with the same UUID but another MAC gets no token', function () {
    User::factory()->create();
    $computer = disklessPc(1, '03000200-0400-0500-0006-000700080009');
    $computer->forceFill(['mac_address' => 'AA:BB:CC:00:11:22'])->save();
    $key = disklessClubKey();
    app(CurrentClub::class)->clear();

    $this->postJson(route('api.agent.enroll'), ['club_key' => $key, 'device_id' => '03000200-0400-0500-0006-000700080009', 'mac_address' => 'AA:BB:CC:99:99:99'])
        ->assertStatus(409)->assertJsonPath('code', 'device_conflict');
    $this->postJson(route('api.agent.enroll'), ['club_key' => $key, 'device_id' => '03000200-0400-0500-0006-000700080009', 'mac_address' => 'aa-bb-cc-00-11-22'])
        ->assertOk();
});

test('a PC identified by its MAC (placeholder UUID) enrolls like any other', function () {
    User::factory()->create();
    $computer = disklessPc(3, 'MAC-AABBCC001122');
    $key = disklessClubKey();
    app(CurrentClub::class)->clear();

    $this->postJson(route('api.agent.enroll'), ['club_key' => $key, 'device_id' => 'MAC-AABBCC001122', 'mac_address' => 'AA:BB:CC:00:11:22'])
        ->assertOk()->assertJsonPath('computer_id', $computer->id);
});
