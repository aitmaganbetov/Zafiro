<?php

use App\Models\ActivityLog;
use App\Models\Club;
use App\Models\ClubComputer;
use App\Models\ClubMembership;
use App\Models\ClubZone;
use App\Models\MemberBalance;
use App\Models\Tariff;
use App\Models\User;
use App\Services\ClubComputerSessions;
use App\Services\CurrentClub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

uses(RefreshDatabase::class);

function securityPc(int $number = 1): ClubComputer
{
    $zone = ClubZone::query()->firstOrCreate(['name' => 'Hall']);

    return ClubComputer::query()->create(['club_zone_id' => $zone->id, 'number' => $number, 'device_name' => "PC-{$number}"]);
}

function securityMember(string $role, string $phone): User
{
    $user = User::factory()->create(['phone' => $phone, 'password' => Hash::make('secret-pass')]);
    ClubMembership::query()->where('user_id', $user->id)->update(['role' => $role]);

    return $user;
}

test('signing in on a PC again revokes that PC\'s previous token, phones keep theirs', function () {
    securityMember('cashier', '+77001112233');
    app(CurrentClub::class)->clear();
    $login = fn (string $device, string $url = '/api/agent/user/login') => $this->postJson($url, ['phone' => '+77001112233', 'password' => 'secret-pass', 'device_name' => $device])->assertSuccessful()->json('access_token');

    $first = $login('PC-07');
    $second = $login('PC-07');
    $phoneA = $login('ios-app', '/api/auth/login');
    $phoneB = $login('ios-app', '/api/auth/login');

    expect(PersonalAccessToken::findToken($first))->toBeNull()
        ->and(PersonalAccessToken::findToken($second))->not->toBeNull()
        ->and(PersonalAccessToken::findToken($phoneA))->not->toBeNull()
        ->and(PersonalAccessToken::findToken($phoneB))->not->toBeNull();
});

test('a customer token reports the agent state but cannot rename the PC or fake its hardware', function () {
    $computer = securityPc();
    $customer = securityMember('cashier', '+77001112234');
    $staff = securityMember('admin', '+77001112235');
    $customerToken = $customer->createToken('PC-1', ['club:'.$computer->club_id])->plainTextToken;
    $staffToken = $staff->createToken('PC-1', ['club:'.$computer->club_id])->plainTextToken;
    app(CurrentClub::class)->clear();

    $this->withToken($customerToken)->postJson(route('api.club-computers.heartbeat', $computer), ['state' => 'locked', 'device_name' => 'HACKED', 'cpu_model' => 'Fake'])
        ->assertSuccessful();
    expect($computer->fresh())->agent_state->toBe('locked')->device_name->toBe('PC-1')->cpu_model->toBeNull();

    app('auth')->forgetGuards();
    $this->withToken($staffToken)->postJson(route('api.club-computers.heartbeat', $computer), ['device_name' => 'PC-01', 'cpu_model' => 'Ryzen'])
        ->assertSuccessful();
    expect($computer->fresh())->device_name->toBe('PC-01')->cpu_model->toBe('Ryzen');
});

test('a PC with a device token sells time only to the agent on that PC', function () {
    $computer = securityPc();
    $other = securityPc(2);
    $deviceToken = app(ClubComputerSessions::class)->issueAgentToken($computer);
    $customer = securityMember('cashier', '+77001112236');
    MemberBalance::query()->where('user_id', $customer->id)->update(['balance' => 1000]);
    $tariff = Tariff::query()->create(['name' => 'Hour', 'duration_minutes' => 60, 'price' => 100, 'is_active' => true]);
    $token = $customer->createToken('PC-1', ['club:'.$computer->club_id])->plainTextToken;
    app(CurrentClub::class)->clear();

    $this->withToken($token)->postJson(route('api.tariffs.purchase', $tariff), ['computer_id' => $computer->id])
        ->assertForbidden()->assertJsonPath('code', 'wrong_computer');
    $this->withToken($token)->postJson(route('api.tariffs.purchase', $tariff), ['computer_id' => $computer->id], ['X-Agent-Token' => 'zag_'.str_repeat('x', 48)])
        ->assertForbidden();
    $this->withToken($token)->postJson(route('api.tariffs.purchase', $tariff), ['computer_id' => $computer->id], ['X-Agent-Token' => $deviceToken])
        ->assertSuccessful()->assertJsonPath('success', true);
    // PCs without a device token (older agents) keep working.
    $this->withToken($token)->postJson(route('api.tariffs.purchase', $tariff), ['computer_id' => $other->id])->assertSuccessful();
});

test('exits and wrong PINs are logged under the user signed in on the PC', function () {
    $computer = securityPc();
    $deviceToken = app(ClubComputerSessions::class)->issueAgentToken($computer);
    Club::query()->findOrFail($computer->club_id)->forceFill(['agent_exit_pin_hash' => bcrypt('4821')])->save();
    $customer = securityMember('cashier', '+77001112237');
    $userToken = $customer->createToken('PC-1', ['club:'.$computer->club_id])->plainTextToken;
    app(CurrentClub::class)->clear();
    $headers = ['X-Agent-Token' => $deviceToken, 'Authorization' => "Bearer {$userToken}"];

    $this->postJson(route('api.agent.exit-pin'), ['pin' => '7319'], $headers)->assertUnprocessable();
    $this->postJson(route('api.agent.exit-pin'), ['pin' => '4821'], $headers)->assertOk();

    $logs = ActivityLog::query()->withoutGlobalScope('club')->whereIn('event', ['agent.exit_pin_failed', 'agent.staff_exit'])->orderBy('id')->get();
    expect($logs->pluck('event')->all())->toBe(['agent.exit_pin_failed', 'agent.staff_exit'])
        ->and($logs->pluck('user_id')->unique()->all())->toBe([$customer->id])
        ->and($logs->pluck('ip_address')->unique()->all())->toBe(['127.0.0.1'])
        ->and(json_encode($logs->toArray()))->not->toContain('4821')->not->toContain('7319');
});

test('PIN guessing is limited per club, not only per PC', function () {
    Club::query()->findOrFail(app(CurrentClub::class)->id())->forceFill(['agent_exit_pin_hash' => bcrypt('4821')])->save();
    $tokens = collect(range(1, 7))->map(fn (int $n) => app(ClubComputerSessions::class)->issueAgentToken(securityPc($n)));
    app(CurrentClub::class)->clear();

    $attempts = 0;
    foreach ($tokens as $token) {
        foreach (range(1, 5) as $try) {
            $status = $this->postJson(route('api.agent.exit-pin'), ['pin' => '0000'], ['X-Agent-Token' => $token])->status();
            if ($status === 429) {
                break 2;
            }
            $attempts++;
        }
    }

    expect($attempts)->toBe(30);
});
