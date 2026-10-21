<?php

use App\Models\ClubComputer;
use App\Models\ClubZone;
use App\Models\User;
use App\Services\ClubComputerSessions;
use App\Services\ComputerPower;
use App\Services\CurrentClub;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function powerPc(int $number, ?string $mac, bool $online): array
{
    $zone = ClubZone::query()->firstOrCreate(['name' => 'Hall']);
    $pc = ClubComputer::query()->create(['club_zone_id' => $zone->id, 'number' => $number]);
    $pc->forceFill(['mac_address' => $mac, 'last_seen_at' => $online ? now() : now()->subHour(), 'status' => $online ? 'online' : 'offline'])->save();

    return [$pc, app(ClubComputerSessions::class)->issueAgentToken($pc)];
}

function powerBeat(string $device, array $body = ['commands' => true])
{
    app(CurrentClub::class)->clear();

    return test()->postJson(route('api.agent.heartbeat'), $body, ['X-Agent-Token' => $device])->assertOk();
}

test('restart and shut down reach the agent with its heartbeat once', function () {
    $staff = User::factory()->create();
    [$pc, $device] = powerPc(1, 'AA:BB:CC:00:00:01', true);

    $this->actingAs($staff)->postJson(route('club-computers.control'), ['computer_ids' => [$pc->id], 'action' => 'restart'])
        ->assertOk()->assertJsonPath('status', 'restarting')->assertJsonPath('queued', 1)
        ->assertJsonPath('message', 'Перезагрузка: ПК получит команду в течение минуты (1 ПК).');

    // An older agent does not ask for commands: the command waits for one that carries it out.
    powerBeat($device, ['state' => 'locked'])->assertJsonMissingPath('power_command');
    powerBeat($device)->assertJsonPath('power_command', 'restart');
    powerBeat($device)->assertJsonPath('power_command', null);

    $this->actingAs($staff)->postJson(route('club-computers.control'), ['computer_ids' => [$pc->id], 'action' => 'power_off'])->assertOk();
    powerBeat($device, ['state' => 'locked', 'commands' => true])->assertJsonPath('power_command', 'shutdown');
});

test('a command the PC did not take in time does not fire on the next boot', function () {
    $staff = User::factory()->create();
    [$pc, $device] = powerPc(1, 'AA:BB:CC:00:00:01', true);

    $this->actingAs($staff)->postJson(route('club-computers.control'), ['computer_ids' => [$pc->id], 'action' => 'power_off'])->assertOk();
    $this->travel(ComputerPower::COMMAND_TTL_SECONDS + 1)->seconds();

    powerBeat($device)->assertJsonPath('power_command', null);
    expect($pc->fresh()->power_command)->toBeNull();
});

test('a PC that is off is woken by the running PCs of the club', function () {
    $staff = User::factory()->create();
    [$off] = powerPc(1, 'AA:BB:CC:00:00:01', false);
    [$noMac] = powerPc(2, null, false);
    [$relay, $relayDevice] = powerPc(3, 'AA:BB:CC:00:00:03', true);

    $this->actingAs($staff)->postJson(route('club-computers.control'), ['computer_ids' => [$off->id, $noMac->id], 'action' => 'power_on'])
        ->assertOk()->assertJsonPath('status', null)
        ->assertJson(['woken' => 1, 'without_mac' => 1, 'relays' => 1])
        ->assertJsonPath('message', 'Сигнал включения отправят другие ПК клуба (Wake-on-LAN): 1 ПК. Без MAC-адреса: 1.');
    // It is not shown as online before it boots.
    expect($off->fresh()->currentStatus())->toBe('offline');

    powerBeat($relayDevice)->assertJsonPath('wake_macs', ['AA:BB:CC:00:00:01']);
    // Once it is up, nobody wakes it any more; nor after the request expires.
    $off->forceFill(['last_seen_at' => now()])->save();
    powerBeat($relayDevice)->assertJsonPath('wake_macs', []);
    $off->forceFill(['last_seen_at' => now()->subHour()])->save();
    $this->travel(ComputerPower::COMMAND_TTL_SECONDS + 1)->seconds();
    $relay->forceFill(['last_seen_at' => now()])->save();
    powerBeat($relayDevice)->assertJsonPath('wake_macs', []);
});

test('turning on is refused in words when nobody can send the signal', function () {
    $staff = User::factory()->create();
    [$off] = powerPc(1, 'AA:BB:CC:00:00:01', false);
    [$noMac] = powerPc(2, null, false);

    $this->actingAs($staff)->postJson(route('club-computers.control'), ['computer_ids' => [$off->id], 'action' => 'power_on'])
        ->assertOk()->assertJsonPath('message', 'Включить некому: в клубе нет включённых ПК с агентом, который отправит сигнал Wake-on-LAN.');
    $this->postJson(route('club-computers.control'), ['computer_ids' => [$noMac->id], 'action' => 'power_on'])
        ->assertOk()->assertJsonPath('message', 'Не удалось включить: агент ещё не сообщил MAC-адрес этих ПК.');
});
