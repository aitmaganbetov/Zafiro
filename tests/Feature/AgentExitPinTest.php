<?php

use App\Models\ActivityLog;
use App\Models\Club;
use App\Models\ClubComputer;
use App\Models\ClubMembership;
use App\Models\ClubZone;
use App\Models\User;
use App\Services\ClubComputerSessions;
use App\Services\CurrentClub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function agentDeviceToken(): string
{
    $zone = ClubZone::query()->create(['name' => 'Hall']);
    $computer = ClubComputer::query()->create(['club_zone_id' => $zone->id, 'number' => 1]);

    return app(ClubComputerSessions::class)->issueAgentToken($computer);
}

test('an owner sets the agent PIN; only its hash is stored and the change is logged', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)->get(route('settings.club'))->assertOk()->assertSee(__('PIN не задан: закрыть агент на ПК сейчас нельзя.'));
    $this->actingAs($owner)->post(route('settings.agent-pin'), ['pin' => '4821', 'pin_confirmation' => '4821'])->assertRedirect();

    $club = Club::query()->findOrFail(app(CurrentClub::class)->id());
    expect($club->agent_exit_pin_hash)->not->toBe('4821')
        ->and(password_verify('4821', $club->agent_exit_pin_hash))->toBeTrue()
        ->and(DB::table('activity_logs')->where('event', 'club.agent_pin_changed')->count())->toBe(1)
        ->and(json_encode(ActivityLog::query()->get()->toArray()))->not->toContain('4821')
        ->and($club->toArray())->not->toHaveKey('agent_exit_pin_hash');
    $this->get(route('settings.club'))->assertSee(__('PIN задан :date', ['date' => $club->agent_exit_pin_changed_at->format('d.m.Y H:i')]));
});

test('the PIN must be 4 to 8 digits and confirmed; managers cannot change it', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner)->post(route('settings.agent-pin'), ['pin' => '12', 'pin_confirmation' => '12'])->assertSessionHasErrors('pin');
    $this->actingAs($owner)->post(route('settings.agent-pin'), ['pin' => '1234', 'pin_confirmation' => '9999'])->assertSessionHasErrors('pin');
    $this->actingAs($owner)->post(route('settings.agent-pin'), ['pin' => 'abcd', 'pin_confirmation' => 'abcd'])->assertSessionHasErrors('pin');

    $manager = User::factory()->create();
    ClubMembership::query()->where('user_id', $manager->id)->update(['role' => 'manager']);
    $this->flushSession();
    $this->actingAs($manager)->post(route('settings.agent-pin'), ['pin' => '1234', 'pin_confirmation' => '1234'])->assertForbidden();
    expect(Club::query()->findOrFail(app(CurrentClub::class)->id())->agent_exit_pin_hash)->toBeNull();
});

test('the agent checks the PIN with its device token', function () {
    $token = agentDeviceToken();
    $club = Club::query()->findOrFail(app(CurrentClub::class)->id());
    app(CurrentClub::class)->clear();

    $this->postJson(route('api.agent.exit-pin'), ['pin' => '4821'], ['X-Agent-Token' => $token])
        ->assertStatus(409)->assertJsonPath('code', 'pin_not_configured');

    $club->forceFill(['agent_exit_pin_hash' => bcrypt('4821')])->save();

    $this->postJson(route('api.agent.exit-pin'), ['pin' => '0000'], ['X-Agent-Token' => $token])
        ->assertUnprocessable()->assertJsonPath('valid', false)->assertJsonPath('code', 'invalid_pin');
    $this->postJson(route('api.agent.exit-pin'), ['pin' => '4821'], ['X-Agent-Token' => $token])
        ->assertOk()->assertJsonPath('valid', true);
    $this->postJson(route('api.agent.exit-pin'), ['pin' => '4821'])->assertUnauthorized();
});

test('guessing the PIN is limited per PC', function () {
    $token = agentDeviceToken();
    Club::query()->findOrFail(app(CurrentClub::class)->id())->forceFill(['agent_exit_pin_hash' => bcrypt('4821')])->save();
    app(CurrentClub::class)->clear();

    foreach (range(1, 5) as $attempt) {
        $this->postJson(route('api.agent.exit-pin'), ['pin' => (string) (1000 + $attempt)], ['X-Agent-Token' => $token])->assertUnprocessable();
    }

    $this->postJson(route('api.agent.exit-pin'), ['pin' => '4821'], ['X-Agent-Token' => $token])->assertStatus(429);
});
