<?php

use App\Models\ActivityLog;
use App\Models\ClubComputer;
use App\Models\ClubMembership;
use App\Models\ClubZone;
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
    [$token] = registerPcToken();
    User::factory()->create(['phone' => '+77012223344']);

    $this->postJson(route('api.agent.register'), ['name' => 'Арман', 'phone' => '+77012223344', 'password' => 'secret-1', 'device_name' => 'PC'], ['X-Agent-Token' => $token])
        ->assertStatus(422)->assertJsonPath('message', 'Этот номер уже зарегистрирован. Войдите со своим паролем.');
    $this->postJson(route('api.agent.register'), ['name' => 'Дана', 'phone' => '+77029998877', 'password' => '123', 'device_name' => 'PC'], ['X-Agent-Token' => $token])
        ->assertStatus(422)->assertJsonValidationErrors('password');
    $this->postJson(route('api.agent.register'), ['name' => 'Дана', 'phone' => '+77029998877', 'password' => 'secret-1', 'device_name' => 'PC'])
        ->assertUnauthorized();
});
