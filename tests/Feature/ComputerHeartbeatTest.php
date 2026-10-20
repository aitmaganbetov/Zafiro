<?php

use App\Models\ChatMessage;
use App\Models\Club;
use App\Models\ClubComputer;
use App\Models\ClubZone;
use App\Models\Friendship;
use App\Models\MemberBalance;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\AgentUpdate;
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

test('with the customer token the heartbeat brings their balance and what is new for them', function () {
    $pc = ClubComputer::query()->create(['club_zone_id' => ClubZone::query()->create(['name' => 'Hall'])->id, 'number' => 4]);
    $device = app(ClubComputerSessions::class)->issueAgentToken($pc);
    $clubId = app(CurrentClub::class)->id();
    $customer = User::factory()->create();
    MemberBalance::query()->where('user_id', $customer->id)->update(['balance' => 1500, 'bonus_balance' => 200]);
    $friend = User::factory()->create();
    Friendship::query()->create(['requester_id' => $friend->id, 'addressee_id' => $customer->id]);
    ChatMessage::query()->create(['customer_id' => $customer->id, 'from_staff' => false, 'body' => 'Привет']);
    $reply = ChatMessage::query()->create(['customer_id' => $customer->id, 'from_staff' => true, 'body' => 'Здравствуйте']);
    $token = $customer->createToken('PC', ['club:'.$clubId])->plainTextToken;
    $beat = function (array $headers) use ($device) {
        app('auth')->forgetGuards();
        app(CurrentClub::class)->clear();

        return test()->postJson(route('api.agent.heartbeat'), ['state' => 'idle'], ['X-Agent-Token' => $device, ...$headers])->assertOk();
    };

    $beat(['Authorization' => 'Bearer '.$token])->assertJsonPath('customer', [
        'user_id' => $customer->id, 'balance' => 1500, 'bonus_balance' => 200, 'friend_requests' => 1, 'chat_last_staff_id' => $reply->id,
    ]);
    // Without a customer, or with a token of another club, there is nothing about anyone.
    $beat([])->assertJsonPath('customer', null);
    $other = Club::query()->create(['name' => 'Другой', 'slug' => 'hb-other']);
    $foreign = $customer->createToken('PC', ['club:'.$other->id])->plainTextToken;
    $beat(['Authorization' => 'Bearer '.$foreign])->assertJsonPath('customer', null);
    $beat(['Authorization' => 'Bearer not-a-token'])->assertJsonPath('customer', null);
});

test('the agent service of a free outdated PC is offered the update a few times', function () {
    $sha = str_repeat('ab', 32);
    PlatformSetting::write(PlatformSetting::AGENT_RELEASE, [
        'latest_version' => '0.0.70', 'released_at' => '2026-10-20', 'download_url' => null,
        'auto_update' => true, 'installer_url' => AgentUpdate::installerUrl('0.0.70'), 'installer_sha256' => $sha,
    ], null);
    $pc = ClubComputer::query()->create(['club_zone_id' => ClubZone::query()->create(['name' => 'Hall'])->id, 'number' => 5]);
    $pc->forceFill(['agent_version' => '0.0.62', 'agent_state' => 'locked'])->save();
    $device = app(ClubComputerSessions::class)->issueAgentToken($pc);
    $club = app(CurrentClub::class)->get();
    $probe = function (array $body = ['can_update' => true]) use ($device) {
        app(CurrentClub::class)->clear();

        return test()->postJson(route('api.agent.heartbeat'), $body, ['X-Agent-Token' => $device])->assertOk();
    };
    $offer = ['version' => '0.0.70', 'url' => 'https://github.com/aitmaganbetov/Zafiro/releases/download/agent-v0.0.70/ZafiroAgentSetup-0.0.70.exe', 'sha256' => $sha];

    // Only the service asks; the agent window does not get it.
    $probe(['state' => 'locked'])->assertJsonPath('agent_update', null);
    // The service probe stays an empty heartbeat: the PC status is the one shown to staff.
    $probe()->assertJsonPath('agent_update', $offer)->assertJsonPath('status', $pc->fresh()->currentStatus());
    // The next offer only after a pause.
    $probe()->assertJsonPath('agent_update', null);

    // A diskless PC loses the update on restart: after MAX_OFFERS offers it is no longer offered.
    for ($i = 1; $i < AgentUpdate::MAX_OFFERS; $i++) {
        $this->travel(AgentUpdate::OFFER_INTERVAL_MINUTES + 1)->minutes();
        $probe()->assertJsonPath('agent_update', $offer);
    }
    $this->travel(AgentUpdate::OFFER_INTERVAL_MINUTES + 1)->minutes();
    $probe()->assertJsonPath('agent_update', null);

    // A PC somebody is signed in on, an updated PC and a club that turned auto-update off get nothing.
    $pc->forceFill(['agent_update_offers' => 0, 'agent_update_offered_at' => null, 'agent_state' => 'idle'])->save();
    $probe()->assertJsonPath('agent_update', null);
    $pc->forceFill(['agent_state' => 'locked', 'agent_version' => '0.0.70'])->save();
    $probe()->assertJsonPath('agent_update', null);
    $pc->forceFill(['agent_version' => '0.0.62'])->save();
    $club->forceFill(['settings' => ['club' => ['agent_auto_update' => false]]])->save();
    $probe()->assertJsonPath('agent_update', null);
    $club->forceFill(['settings' => ['club' => ['agent_auto_update' => true]]])->save();
    $probe()->assertJsonPath('agent_update', $offer);

    // Without auto-update in the release the panel still tells the club, the PCs install nothing.
    $pc->forceFill(['agent_update_offers' => 0, 'agent_update_offered_at' => null])->save();
    PlatformSetting::write(PlatformSetting::AGENT_RELEASE, ['latest_version' => '0.0.70', 'released_at' => '2026-10-20', 'auto_update' => false], null);
    $probe()->assertJsonPath('agent_update', null);
});
