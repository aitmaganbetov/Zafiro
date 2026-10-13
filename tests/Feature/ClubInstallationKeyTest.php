<?php

use App\Models\Club;
use App\Models\SaasPlan;
use App\Models\User;
use App\Services\CurrentClub;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('each club receives a permanent installation key stored encrypted and hashed', function () {
    $owner = User::factory()->create();
    $club = $owner->currentMembership()->firstOrFail()->club;
    $key = $club->installation_key;

    $this->actingAs($owner)->get(route('settings.club'))
        ->assertSuccessful()
        ->assertSee($key);
    $this->get(route('settings.api'))->assertSuccessful()->assertDontSee($key);

    expect(strlen($key))->toBe(64)
        ->and($club->fresh()->installation_key_hash)->toBe(hash('sha256', $key))
        ->and($club->fresh()->installation_key_prefix)->toBe(substr($key, 0, 12));

    expect(fn () => $club->forceFill(['installation_key' => str_repeat('x', 64)])->save())
        ->toThrow(LogicException::class);

    $this->postJson(route('api.clubs.resolve'), ['club_key' => $key])
        ->assertSuccessful()
        ->assertJsonPath('club_id', $club->id)
        ->assertJsonPath('club_name', $club->name);

    $this->postJson(route('api.clubs.resolve'), ['club_key' => str_repeat('x', 64)])
        ->assertUnauthorized();
});

test('installation key binds api login to its club for a multi-club user', function () {
    $currentClub = app(CurrentClub::class);
    $otherClub = $currentClub->get();
    $installationClub = Club::query()->create(['name' => 'Installation Club', 'slug' => 'installation-club']);
    $currentClub->set($installationClub);
    $user = User::factory()->create([
        'phone' => '+77001234567',
        'password' => 'secret-password',
    ]);
    $otherClub->memberships()->create(['user_id' => $user->id, 'role' => 'Гость']);

    $plan = SaasPlan::query()->firstOrCreate(
        ['slug' => 'trial'],
        ['name' => 'Пробный период', 'trial_days' => 14, 'is_active' => true],
    );
    $installationClub->subscription()->create([
        'saas_plan_id' => $plan->id,
        'status' => 'active',
        'started_at' => now(),
    ]);
    $key = $installationClub->installation_key;

    $this->postJson(route('api.auth.login'), [
        'phone' => '+77001234567',
        'password' => 'secret-password',
        'club_key' => $key,
    ])->assertSuccessful()
        ->assertJsonPath('club_id', $installationClub->id)
        ->assertJsonPath('club_name', 'Installation Club');

    $this->postJson(route('api.auth.login'), [
        'phone' => '+77001234567',
        'password' => 'secret-password',
        'club_key' => $key,
        'club_id' => $otherClub->id,
    ])->assertForbidden();
});

test('installation key change and revoke routes do not exist', function () {
    $manager = User::factory()->create();
    $manager->currentMembership()->firstOrFail()->update(['role' => 'manager']);

    $this->actingAs($manager)->get(route('settings.api'))
        ->assertSuccessful()
        ->assertDontSee($manager->currentMembership()->firstOrFail()->club->installation_key);

    expect(Route::has('settings.api-key.rotate'))->toBeFalse()
        ->and(Route::has('settings.api-key.revoke'))->toBeFalse();
});
