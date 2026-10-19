<?php

use App\Models\ActivityLog;
use App\Models\Club;
use App\Models\ClubComputer;
use App\Models\ClubMembership;
use App\Models\ClubZone;
use App\Models\MemberBalance;
use App\Models\User;
use App\Services\ClubComputerSessions;
use App\Services\CurrentClub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

function registerPcToken(): array
{
    $computer = ClubComputer::query()->create(['club_zone_id' => ClubZone::query()->create(['name' => 'Hall'])->id, 'number' => 1]);
    $token = app(ClubComputerSessions::class)->issueAgentToken($computer);
    $clubId = $computer->club_id;
    app(CurrentClub::class)->clear();

    return [$token, $clubId];
}

test('a customer registers on a club PC and is signed in as a customer of that club', function () {
    [$token, $clubId] = registerPcToken();

    $response = $this->postJson(route('api.agent.register'), ['name' => 'Арман', 'phone' => '8 701 222 33 44', 'password' => 'secret-1', 'device_name' => 'PC-01'], ['X-Agent-Token' => $token])
        ->assertCreated()->assertJsonPath('club_id', $clubId)->assertJsonPath('user.name', 'Арман');

    $user = User::query()->where('phone', '+77012223344')->sole();
    expect(Hash::check('secret-1', $user->password))->toBeTrue()
        ->and(ClubMembership::query()->withoutGlobalScope('club')->where('user_id', $user->id)->sole())
        ->club_id->toBe($clubId)->role->toBe('client')
        ->and(ActivityLog::query()->withoutGlobalScope('club')->where('event', 'user.registered')->exists())->toBeTrue();

    app('auth')->forgetGuards();
    $this->getJson(route('api.me'), ['Authorization' => 'Bearer '.$response->json('access_token')])->assertOk();
});

test('registration refuses a taken phone, a short password and requests without the PC token', function () {
    [$token, $clubId] = registerPcToken();
    $member = User::factory()->create(['phone' => '+77012223344', 'password' => 'secret-1']);
    app(CurrentClub::class)->set(Club::query()->findOrFail($clubId));
    ClubMembership::query()->create(['user_id' => $member->id, 'role' => 'client', 'discount' => 0]);
    app(CurrentClub::class)->clear();

    $this->postJson(route('api.agent.register'), ['name' => 'Арман', 'phone' => '+77012223344', 'password' => 'secret-1', 'device_name' => 'PC'], ['X-Agent-Token' => $token])
        ->assertStatus(422)->assertJsonPath('message', 'Вы уже клиент этого клуба. Войдите со своим паролем.');
    $this->postJson(route('api.agent.register'), ['name' => 'Дана', 'phone' => '+77029998877', 'password' => '123', 'device_name' => 'PC'], ['X-Agent-Token' => $token])
        ->assertStatus(422)->assertJsonValidationErrors('password');
    $this->postJson(route('api.agent.register'), ['name' => 'Дана', 'phone' => '+77029998877', 'password' => 'secret-1', 'device_name' => 'PC'])
        ->assertUnauthorized();
});

test('a customer of another club joins this club with the password of the account', function () {
    [$token, $clubId] = registerPcToken();
    $otherClub = Club::query()->create(['name' => 'Другой клуб', 'slug' => 'join-other-club']);
    $user = User::factory()->create(['name' => 'Арман', 'phone' => '+77012223344', 'password' => 'secret-1']);
    app(CurrentClub::class)->set($otherClub);
    ClubMembership::query()->create(['user_id' => $user->id, 'role' => 'client', 'discount' => 0]);
    MemberBalance::query()->create(['user_id' => $user->id, 'balance' => 500, 'bonus_balance' => 0]);
    app(CurrentClub::class)->clear();

    $this->postJson(route('api.agent.user.login'), ['phone' => '+77012223344', 'password' => 'secret-1', 'club_id' => $clubId, 'device_name' => 'PC'])
        ->assertForbidden()->assertJsonPath('code', 'not_club_member');

    $this->postJson(route('api.agent.register'), ['name' => 'Чужое имя', 'phone' => '+77012223344', 'password' => 'wrong-pass', 'device_name' => 'PC'], ['X-Agent-Token' => $token])
        ->assertStatus(422)->assertJsonValidationErrors('password');
    expect(ClubMembership::query()->withoutGlobalScope('club')->where('user_id', $user->id)->count())->toBe(1);

    $response = $this->postJson(route('api.agent.register'), ['name' => 'Чужое имя', 'phone' => '87012223344', 'password' => 'secret-1', 'device_name' => 'PC'], ['X-Agent-Token' => $token])
        ->assertCreated()->assertJsonPath('club_id', $clubId)->assertJsonPath('user.name', 'Арман');

    expect(User::query()->where('phone', '+77012223344')->count())->toBe(1)
        ->and(ClubMembership::query()->withoutGlobalScope('club')->where('user_id', $user->id)->pluck('club_id')->sort()->values()->all())
        ->toBe(collect([$clubId, $otherClub->id])->sort()->values()->all())
        ->and((float) MemberBalance::query()->withoutGlobalScope('club')->where('user_id', $user->id)->where('club_id', $clubId)->value('balance'))->toBe(0.0)
        ->and((float) MemberBalance::query()->withoutGlobalScope('club')->where('user_id', $user->id)->where('club_id', $otherClub->id)->value('balance'))->toBe(500.0)
        ->and(ActivityLog::query()->withoutGlobalScope('club')->where('event', 'user.joined_club')->where('club_id', $clubId)->exists())->toBeTrue();

    app('auth')->forgetGuards();
    $this->getJson(route('api.me'), ['Authorization' => 'Bearer '.$response->json('access_token')])->assertOk();
    expect($user->tokens()->sole()->abilities)->toBe(['club:'.$clubId]);
});
