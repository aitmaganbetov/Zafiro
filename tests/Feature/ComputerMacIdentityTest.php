<?php

use App\Models\Club;
use App\Models\ClubComputer;
use App\Models\ClubZone;
use App\Models\User;
use App\Services\ClubComputerSessions;
use App\Services\CurrentClub;
use App\Support\MacAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Everything a PC takes from the boot image of a diskless club: the same on every PC.
const IMAGE_HOSTNAME = 'ZAFIRO-IMAGE';
const IMAGE_UUID = '4C4C4544-0042-3510-8051-B4C04F4A3132';
const IMAGE_MACS = ['aa:bb:cc:00:00:01', 'AA-BB-CC-00-00-02', 'aabbcc000003'];

function imageClubKey(): string
{
    return Club::query()->findOrFail(app(CurrentClub::class)->id())->installation_key;
}

/** @return array<string, string> heartbeat headers of a PC whose agent identifies it by its MAC */
function macHeaders(string $token, string $mac): array
{
    return ['X-Agent-Token' => $token, 'X-Agent-Device' => MacAddress::deviceId(MacAddress::normalize($mac))];
}

test('MAC addresses are normalized to AA:BB:CC:DD:EE:FF', function () {
    expect(MacAddress::normalize('aa-bb-cc-dd-ee-0f'))->toBe('AA:BB:CC:DD:EE:0F')
        ->and(MacAddress::normalize('aabb.ccdd.ee0f'))->toBe('AA:BB:CC:DD:EE:0F')
        ->and(MacAddress::normalize('00:00:00:00:00:00'))->toBeNull()
        ->and(MacAddress::normalize('FF:FF:FF:FF:FF:FF'))->toBeNull()
        ->and(MacAddress::normalize('AA:BB:CC'))->toBeNull()
        ->and(MacAddress::normalize(null))->toBeNull();
});

test('three PCs booted from one image get three cards and none of them loses its MAC or token', function () {
    User::factory()->create();
    $key = imageClubKey();
    app(CurrentClub::class)->clear();

    $pcs = [];
    foreach (IMAGE_MACS as $mac) {
        $response = $this->postJson(route('api.agent.enroll'), ['club_key' => $key, 'device_id' => IMAGE_UUID, 'device_name' => IMAGE_HOSTNAME, 'mac_address' => $mac])
            ->assertOk();
        $pcs[] = ['mac' => $mac, 'id' => $response->json('computer_id'), 'token' => $response->json('agent_token')];

        // Every PC already registered keeps working after the next one is switched on.
        foreach ($pcs as $pc) {
            $this->postJson(route('api.agent.heartbeat'), ['mac_address' => MacAddress::normalize($pc['mac'])], macHeaders($pc['token'], $pc['mac']))
                ->assertOk()->assertJsonPath('computer_id', $pc['id']);
        }
    }

    $computers = ClubComputer::query()->withoutGlobalScope('club')->orderBy('id')->get();
    expect($computers)->toHaveCount(3)
        ->and($computers->pluck('id')->all())->toBe(array_column($pcs, 'id'))
        ->and($computers->pluck('mac_address')->all())->toBe(['AA:BB:CC:00:00:01', 'AA:BB:CC:00:00:02', 'AA:BB:CC:00:00:03'])
        ->and($computers->pluck('number')->all())->toBe([1, 2, 3]);

    // A restart of PC 1 (diskless: the token is gone) finds its own card again.
    $this->postJson(route('api.agent.enroll'), ['club_key' => $key, 'device_id' => IMAGE_UUID, 'device_name' => IMAGE_HOSTNAME, 'mac_address' => IMAGE_MACS[0]])
        ->assertOk()->assertJsonPath('computer_id', $pcs[0]['id']);
    expect(ClubComputer::query()->withoutGlobalScope('club')->count())->toBe(3);
});

test('staff connecting three PCs of one image get three cards', function () {
    $user = User::factory()->create();
    $token = $user->createToken('agent', ['club:'.$user->currentMembership()->value('club_id')])->plainTextToken;
    app(CurrentClub::class)->clear();

    $ids = [];
    foreach (IMAGE_MACS as $mac) {
        $identity = ['device_name' => IMAGE_HOSTNAME, 'device_id' => IMAGE_UUID, 'mac_address' => $mac, 'claim' => true];
        // The agent asks first whether the PC is known: a new MAC is not, whatever its name and UUID.
        $this->withToken($token)->postJson(route('api.club-computers.resolve'), $identity)
            ->assertNotFound()->assertJsonPath('code', 'computer_not_found');
        $ids[] = $this->withToken($token)->postJson(route('api.club-computers.register'), $identity)
            ->assertCreated()->json('computer_id');
        $this->withToken($token)->postJson(route('api.club-computers.resolve'), $identity)
            ->assertOk()->assertJsonPath('computer_id', end($ids));
    }

    expect(array_unique($ids))->toHaveCount(3)
        ->and(ClubComputer::query()->withoutGlobalScope('club')->orderBy('id')->pluck('mac_address')->all())
        ->toBe(['AA:BB:CC:00:00:01', 'AA:BB:CC:00:00:02', 'AA:BB:CC:00:00:03']);
});

test('a heartbeat never moves another MAC into a card', function () {
    User::factory()->create();
    $zone = ClubZone::query()->create(['name' => 'Hall']);
    $first = ClubComputer::query()->create(['club_zone_id' => $zone->id, 'number' => 1]);
    $first->forceFill(['mac_address' => 'AA:BB:CC:00:00:01'])->save();
    $empty = ClubComputer::query()->create(['club_zone_id' => $zone->id, 'number' => 2]);
    $sessions = app(ClubComputerSessions::class);
    $firstToken = $sessions->issueAgentToken($first);
    $emptyToken = $sessions->issueAgentToken($empty);
    app(CurrentClub::class)->clear();

    // PC 2 runs with the token of PC 1 (it came with the image).
    $this->postJson(route('api.agent.heartbeat'), ['mac_address' => 'AA:BB:CC:00:00:02'], ['X-Agent-Token' => $firstToken])->assertOk();
    // A card without a MAC does not take the MAC of another card either.
    $this->postJson(route('api.agent.heartbeat'), ['mac_address' => 'aa:bb:cc:00:00:01'], ['X-Agent-Token' => $emptyToken])->assertOk();

    expect($first->fresh()->mac_address)->toBe('AA:BB:CC:00:00:01')
        ->and($empty->fresh()->mac_address)->toBeNull();

    // A card without a MAC gets the MAC its PC reports.
    $this->postJson(route('api.agent.heartbeat'), ['mac_address' => 'aa:bb:cc:00:00:09'], ['X-Agent-Token' => $emptyToken])->assertOk();
    expect($empty->fresh()->mac_address)->toBe('AA:BB:CC:00:00:09');
});

test('a token copied with the image does not work on a PC with another MAC', function () {
    User::factory()->create();
    $zone = ClubZone::query()->create(['name' => 'Hall']);
    $computer = ClubComputer::query()->create(['club_zone_id' => $zone->id, 'number' => 1]);
    $computer->forceFill(['device_id' => IMAGE_UUID, 'mac_address' => 'AA:BB:CC:00:00:01'])->save();
    $token = app(ClubComputerSessions::class)->issueAgentToken($computer);
    app(CurrentClub::class)->clear();

    $this->postJson(route('api.agent.heartbeat'), [], macHeaders($token, IMAGE_MACS[1]))
        ->assertUnauthorized()->assertJsonPath('code', 'device_mismatch');
    // Its own PC: by the MAC (new agents) and by the UUID (older agents).
    $this->postJson(route('api.agent.heartbeat'), [], macHeaders($token, IMAGE_MACS[0]))->assertOk();
    $this->postJson(route('api.agent.heartbeat'), [], ['X-Agent-Token' => $token, 'X-Agent-Device' => IMAGE_UUID])->assertOk();
});

test('a card registered without a MAC is taken over by its PC instead of a new card', function () {
    $user = User::factory()->create();
    $zone = ClubZone::query()->create(['name' => 'Hall']);
    $computer = ClubComputer::query()->create(['club_zone_id' => $zone->id, 'number' => 5]);
    $computer->forceFill(['device_id' => 'OLD-UUID'])->save();
    $key = imageClubKey();
    app(CurrentClub::class)->clear();

    $this->postJson(route('api.agent.enroll'), ['club_key' => $key, 'device_id' => 'OLD-UUID', 'mac_address' => IMAGE_MACS[0]])
        ->assertOk()->assertJsonPath('computer_id', $computer->id);
    expect($computer->fresh()->mac_address)->toBe('AA:BB:CC:00:00:01');

    // From now on the UUID alone does not lead to this card.
    $other = $this->postJson(route('api.agent.enroll'), ['club_key' => $key, 'device_id' => 'OLD-UUID', 'mac_address' => IMAGE_MACS[1]])
        ->assertOk()->json('computer_id');
    expect($other)->not->toBe($computer->id)
        ->and($computer->fresh()->mac_address)->toBe('AA:BB:CC:00:00:01');
});

test('only staff move a MAC to another card, and a MAC stays on one card', function () {
    $user = User::factory()->create();
    $zone = ClubZone::query()->create(['name' => 'Hall', 'icon' => 'desktop']);
    $first = ClubComputer::query()->create(['club_zone_id' => $zone->id, 'number' => 1, 'position_x' => 5, 'position_y' => 5]);
    $first->forceFill(['mac_address' => 'AA:BB:CC:00:00:01'])->save();
    $second = ClubComputer::query()->create(['club_zone_id' => $zone->id, 'number' => 2, 'position_x' => 52, 'position_y' => 5]);

    $this->actingAs($user)->patch(route('club-computers.update', $second), [
        'number' => 2, 'club_zone_id' => $zone->id, 'mac_address' => 'aa-bb-cc-00-00-01', 'edit_mode' => 1,
    ])->assertSessionHasErrors('mac_address');
    $this->patch(route('club-computers.update', $second), [
        'number' => 2, 'club_zone_id' => $zone->id, 'mac_address' => 'not a mac', 'edit_mode' => 1,
    ])->assertSessionHasErrors('mac_address');

    $this->patch(route('club-computers.update', $first), [
        'number' => 1, 'club_zone_id' => $zone->id, 'mac_address' => '', 'edit_mode' => 1,
    ])->assertSessionHasNoErrors();
    $this->patch(route('club-computers.update', $second), [
        'number' => 2, 'club_zone_id' => $zone->id, 'mac_address' => 'aa-bb-cc-00-00-01', 'edit_mode' => 1,
    ])->assertSessionHasNoErrors();

    expect($first->fresh()->mac_address)->toBeNull()
        ->and($second->fresh()->mac_address)->toBe('AA:BB:CC:00:00:01');
});
