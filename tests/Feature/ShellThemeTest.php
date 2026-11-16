<?php

use App\Models\Club;
use App\Models\ClubComputer;
use App\Models\ClubZone;
use App\Models\User;
use App\Services\ClubComputerSessions;
use App\Services\CurrentClub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

function shellThemeSave(User $owner, mixed $theme): TestResponse
{
    return test()->actingAs($owner)->post(route('settings.save'), [
        'section' => 'club',
        'session_idle_timeout' => 15,
        'pc_restart_after_session_end' => 0.5,
        'session_auto_terminate_when_pc_unavailable' => 5,
        'booking_interval' => 30,
        'agent_shell_theme' => $theme,
    ]);
}

test('the club picks a colour theme for its PCs and the agent gets its colours with the heartbeat', function () {
    $owner = User::factory()->create();
    $token = app(ClubComputerSessions::class)->issueAgentToken(ClubComputer::query()->create(['club_zone_id' => ClubZone::query()->create(['name' => 'Hall'])->id, 'number' => 1]));

    // Until the club picks one, the agent keeps its own look.
    $this->postJson(route('api.agent.heartbeat'), [], ['X-Agent-Token' => $token])->assertOk()
        ->assertJsonPath('club.shell_theme', null);

    $this->actingAs($owner)->get(route('settings.club'))->assertOk()
        ->assertSee('Тема оформления на ПК')->assertSee('Красная')
        ->assertSee('name="agent_shell_theme" value="standard" class="sr-only" checked', false);
    shellThemeSave($owner, 'red')->assertRedirect()->assertSessionHasNoErrors();

    expect(Club::query()->findOrFail(app(CurrentClub::class)->id())->settings['club']['agent_shell_theme'])->toBe('red');
    $this->actingAs($owner)->get(route('settings.club'))
        ->assertSee('name="agent_shell_theme" value="red" class="sr-only" checked', false);

    app(CurrentClub::class)->clear();
    $this->postJson(route('api.agent.heartbeat'), [], ['X-Agent-Token' => $token])->assertOk()
        ->assertJsonPath('club.shell_theme.accent', '#dc2626')
        ->assertJsonPath('club.shell_theme.bg_0', '#0a0304');
});

test('only the listed themes are accepted', function () {
    $owner = User::factory()->create();

    shellThemeSave($owner, 'url(javascript:alert(1))')->assertSessionHasErrors('agent_shell_theme');

    expect(Club::query()->findOrFail(app(CurrentClub::class)->id())->settings['club']['agent_shell_theme'] ?? null)->toBeNull();
});
