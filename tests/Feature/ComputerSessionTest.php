<?php

use App\Models\ActivityLog;
use App\Models\ClubComputer;
use App\Models\ClubMembership;
use App\Models\ClubSubscription;
use App\Models\ClubZone;
use App\Models\User;
use App\Services\ClubComputerSessions;
use App\Services\CurrentClub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/** A PC with a paid session of $owner that ends in $minutes. */
function paidComputer(User $owner, int $minutes = 60): ClubComputer
{
    $zone = ClubZone::query()->create(['name' => 'Hall']);

    return ClubComputer::query()->create([
        'club_zone_id' => $zone->id,
        'number' => 1,
        'device_name' => 'PC-01',
        'current_session_id' => 'paid-session',
        'current_session_user_id' => $owner->id,
        'session_ends_at' => now()->addMinutes($minutes),
    ]);
}

function tokenFor(User $user, ClubComputer $computer): string
{
    return $user->createToken('agent', ['club:'.$computer->club_id])->plainTextToken;
}

afterEach(fn () => Carbon::setTestNow());

test('the agent cannot move the end of a paid session', function () {
    $customer = User::factory()->create();
    $computer = paidComputer($customer);
    $endsAt = $computer->session_ends_at->utc()->toIso8601String();
    $token = tokenFor($customer, $computer);
    app(CurrentClub::class)->clear();

    $this->withToken($token)->postJson(route('api.club-computers.heartbeat', $computer), [
        'state' => 'in_session',
        'session_id' => 'paid-session',
        'session_ends_at' => '2099-01-01T00:00:00Z',
    ])->assertSuccessful()->assertJsonPath('session.ends_at', $endsAt);

    expect($computer->fresh()->session_ends_at->utc()->toIso8601String())->toBe($endsAt);
});

test('idle, locked or restarting agents do not erase a paid session', function (string $state) {
    $customer = User::factory()->create();
    $computer = paidComputer($customer);
    $token = tokenFor($customer, $computer);
    app(CurrentClub::class)->clear();

    $this->withToken($token)->postJson(route('api.club-computers.heartbeat', $computer), ['state' => $state])
        ->assertSuccessful()
        ->assertJsonPath('session.id', 'paid-session')
        ->assertJsonPath('session.user_id', $customer->id);

    expect($computer->fresh())->current_session_id->toBe('paid-session')->agent_state->toBe($state);
})->with(['idle', 'locked', 'shutting_down']);

test('another club member cannot take over or wipe a paid session', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    ClubMembership::query()->where('user_id', $other->id)->update(['role' => 'cashier']);
    $computer = paidComputer($owner);
    $token = tokenFor($other, $computer);
    app(CurrentClub::class)->clear();

    $this->withToken($token)->postJson(route('api.club-computers.heartbeat', $computer), ['state' => 'in_session', 'session_id' => 'stolen'])
        ->assertUnprocessable();
    $this->withToken($token)->postJson(route('api.club-computers.session.end', $computer), ['session_id' => 'paid-session'])
        ->assertForbidden();

    expect($computer->fresh()->current_session_user_id)->toBe($owner->id);
});

test('a paid session ends when its time is over: on heartbeat and by the scheduler', function () {
    $customer = User::factory()->create();
    $computer = paidComputer($customer, minutes: 30);
    $second = ClubComputer::query()->create(['club_zone_id' => $computer->club_zone_id, 'number' => 2, 'current_session_id' => 's2', 'current_session_user_id' => $customer->id, 'session_ends_at' => now()->addMinutes(10)]);
    $token = tokenFor($customer, $computer);
    app(CurrentClub::class)->clear();
    Carbon::setTestNow(now()->addMinutes(31));

    $this->withToken($token)->postJson(route('api.club-computers.heartbeat', $computer), ['state' => 'in_session', 'session_id' => 'paid-session'])
        ->assertSuccessful()
        ->assertJsonPath('session', null)
        ->assertJsonPath('session_expired', true);
    expect($computer->fresh()->current_session_id)->toBeNull();

    $this->artisan('club-computers:expire-sessions')->assertSuccessful();
    expect(ClubComputer::query()->withoutGlobalScope('club')->find($second->id)->current_session_id)->toBeNull();
});

test('the owner or staff end a session early and it is logged', function () {
    $owner = User::factory()->create();
    ClubMembership::query()->where('user_id', $owner->id)->update(['role' => 'cashier']);
    $computer = paidComputer($owner);
    $ownerToken = tokenFor($owner, $computer);
    app(CurrentClub::class)->clear();

    $this->withToken($ownerToken)->postJson(route('api.club-computers.session.end', $computer), ['session_id' => 'wrong'])->assertUnprocessable();
    $this->withToken($ownerToken)->postJson(route('api.club-computers.session.end', $computer), ['session_id' => 'paid-session'])
        ->assertSuccessful()
        ->assertJsonPath('session', null);

    expect($computer->fresh()->current_session_id)->toBeNull()
        ->and(ActivityLog::query()->withoutGlobalScope('club')->where('event', 'computer.session_ended')->sole()->user_id)->toBe($owner->id);
});

test('staff get a device token for the PC, customers do not', function () {
    $staff = User::factory()->create(); // owner
    $customer = User::factory()->create();
    ClubMembership::query()->where('user_id', $customer->id)->update(['role' => 'cashier']);
    $zone = ClubZone::query()->create(['name' => 'Hall']);
    $computer = ClubComputer::query()->create(['club_zone_id' => $zone->id, 'number' => 3, 'device_name' => 'PC-03']);
    $staffToken = tokenFor($staff, $computer);
    $customerToken = tokenFor($customer, $computer);
    app(CurrentClub::class)->clear();

    $this->withToken($customerToken)->postJson(route('api.club-computers.resolve'), ['device_name' => 'PC-03'])
        ->assertSuccessful()->assertJsonMissingPath('agent_token');
    app('auth')->forgetGuards(); // next request uses another token
    $first = $this->withToken($staffToken)->postJson(route('api.club-computers.resolve'), ['device_name' => 'PC-03'])
        ->assertSuccessful()->json('agent_token');
    $second = $this->withToken($staffToken)->postJson(route('api.club-computers.resolve'), ['device_name' => 'PC-03'])->json('agent_token');

    expect($first)->toMatch('/^zag_[A-Za-z0-9]{48}$/')
        ->and($computer->fresh()->agent_token_hash)->toBe(hash('sha256', $second))
        ->and($computer->fresh()->toArray())->not->toHaveKey('agent_token_hash');

    $this->postJson(route('api.agent.heartbeat'), ['state' => 'idle'], ['X-Agent-Token' => $first])->assertUnauthorized();
    $this->postJson(route('api.agent.heartbeat'), ['state' => 'idle'], ['X-Agent-Token' => $second])
        ->assertSuccessful()
        ->assertJsonPath('computer_id', $computer->id)
        ->assertJsonPath('session', null);
    expect($computer->fresh()->agent_state)->toBe('idle');
});

test('a device token works only for its own PC and needs an active subscription', function () {
    $staff = User::factory()->create();
    $zone = ClubZone::query()->create(['name' => 'Hall']);
    $computer = ClubComputer::query()->create(['club_zone_id' => $zone->id, 'number' => 4]);
    $token = app(ClubComputerSessions::class)->issueAgentToken($computer);
    app(CurrentClub::class)->clear();

    $this->postJson(route('api.agent.heartbeat'), [])->assertUnauthorized()->assertJsonPath('code', 'invalid_agent_token');
    $this->postJson(route('api.agent.heartbeat'), [], ['X-Agent-Token' => $token])->assertSuccessful()->assertJsonPath('computer_id', $computer->id);

    ClubSubscription::query()->where('club_id', $computer->club_id)->update(['status' => 'canceled']);
    $this->postJson(route('api.agent.heartbeat'), [], ['X-Agent-Token' => $token])->assertStatus(402);
});
