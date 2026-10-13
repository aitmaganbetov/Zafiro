<?php

use App\Models\ActivityLog;
use App\Models\Club;
use App\Models\ClubComputer;
use App\Models\ClubInvitation;
use App\Models\ClubZone;
use App\Models\User;
use App\Services\CurrentClub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

function latestLog(string $event): ActivityLog
{
    return ActivityLog::query()->withoutGlobalScope('club')->where('event', $event)->latest('id')->firstOrFail();
}

test('creating and accepting a club invitation is logged without the token', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)->post(route('club-invitations.store'), [
        'email' => 'cashier@example.com',
        'role' => 'cashier',
    ])->assertRedirect();

    $created = latestLog('invitation.created');
    $token = basename(parse_url(session('invite_url'), PHP_URL_PATH));
    expect($created->user_id)->toBe($owner->id)
        ->and($created->ip_address)->not->toBeEmpty()
        ->and($created->details)->toMatchArray(['role' => 'cashier', 'email' => 'cashier@example.com'])
        ->and(json_encode($created->details))->not->toContain($token);

    auth()->logout();
    $this->post(route('club-invitations.accept', $token), [
        'name' => 'Новый кассир',
        'phone' => '7001112233',
        'password' => 'cashier-secret',
        'password_confirmation' => 'cashier-secret',
    ])->assertRedirect(route('dashboard'));

    $accepted = latestLog('invitation.accepted');
    $cashier = User::query()->where('phone', '+77001112233')->firstOrFail();
    expect($accepted->user_id)->toBe($cashier->id)
        ->and($accepted->club_id)->toBe(ClubInvitation::query()->withoutGlobalScope('club')->value('club_id'))
        ->and($accepted->details)->toMatchArray(['role' => 'cashier', 'new_account' => true]);
});

test('switching clubs is logged in the club the user switched to', function () {
    $user = User::factory()->create();
    $fromClubId = app(CurrentClub::class)->id();
    $other = Club::query()->create(['name' => 'Второй клуб', 'slug' => 'second-club']);
    DB::table('club_memberships')->insert(['club_id' => $other->id, 'user_id' => $user->id, 'role' => 'owner', 'discount' => 0]);

    $this->actingAs($user)->post(route('clubs.switch'), ['club_id' => $other->id])->assertRedirect();

    $log = latestLog('club.switched');
    expect($log->club_id)->toBe($other->id)
        ->and($log->user_id)->toBe($user->id)
        ->and($log->details)->toMatchArray(['from_club_id' => $fromClubId]);
});

test('failed web sign-in for an existing account is logged with the reason', function () {
    $user = User::factory()->create(['password' => 'right-secret']);

    $this->post(route('auth.login'), ['phone' => substr($user->phone, 2), 'password' => 'wrong-secret'])
        ->assertSessionHasErrors('phone');

    $log = latestLog('auth.login_failed');
    expect($log->user_id)->toBe($user->id)
        ->and($log->ip_address)->not->toBeEmpty()
        ->and($log->details)->toBe(['reason' => 'invalid_credentials'])
        ->and(json_encode($log))->not->toContain('wrong-secret');
});

test('failed sign-in for an unknown phone goes to the system log', function () {
    Log::spy();

    $this->post(route('auth.login'), ['phone' => '7009998877', 'password' => 'whatever'])
        ->assertSessionHasErrors('phone');

    expect(ActivityLog::query()->withoutGlobalScope('club')->where('event', 'auth.login_failed')->exists())->toBeFalse();
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $context['phone'] === '+77009998877'
        && $context['reason'] === 'invalid_credentials'
        && $context['ip'] === '127.0.0.1');
});

test('failed API sign-ins are logged with the reason', function () {
    $user = User::factory()->create(['password' => 'right-secret']);

    $this->postJson(route('api.auth.login'), ['phone' => $user->phone, 'password' => 'nope', 'device_name' => 'PC-01'])
        ->assertUnauthorized();
    expect(latestLog('auth.api_login_failed')->details)->toBe(['reason' => 'invalid_credentials', 'device' => 'PC-01']);

    $this->postJson(route('api.auth.login'), ['phone' => $user->phone, 'password' => 'right-secret', 'club_key' => str_repeat('x', 64)])
        ->assertUnauthorized();
    $log = latestLog('auth.api_login_failed');
    expect($log->user_id)->toBe($user->id)
        ->and($log->details['reason'])->toBe('invalid_club_key');
});

test('settings changes are logged with old and new values and render in the log', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('settings.save'), [
        'section' => 'guests',
        'show_qr_code_on_login' => '1',
    ])->assertRedirect();
    $this->post(route('settings.save'), ['section' => 'finances', 'currency' => 'KZT'])->assertRedirect();

    $log = latestLog('settings.updated');
    expect($log->user_id)->toBe($user->id)
        ->and($log->details)->toBe(['section' => 'guests', 'show_qr_code_on_login' => 'нет → да'])
        ->and(ActivityLog::query()->where('event', 'settings.updated')->count())->toBe(1);

    $this->get(route('activity-logs.index'))->assertSuccessful()->assertSee('show_qr_code_on_login: нет → да');
});

test('zone create, update and delete are logged', function () {
    $user = User::factory()->create();

    $zoneData = ['icon' => 'desktop', 'theme' => 'neutral', 'status' => 'available', 'is_featured' => false, 'sort_order' => 0];
    $this->actingAs($user)->post(route('club-map.store'), ['name' => 'VIP', 'capacity' => 4, ...$zoneData])->assertRedirect();
    $zone = ClubZone::query()->firstOrFail();
    expect(latestLog('zone.created')->subject_id)->toBe($zone->id);

    $this->put(route('club-map.update', $zone), ['name' => 'VIP Premium', 'capacity' => 6, ...$zoneData])->assertRedirect();
    expect(latestLog('zone.updated')->details)->toMatchArray(['name' => 'VIP → VIP Premium', 'capacity' => '4 → 6']);

    ClubComputer::query()->create(['club_zone_id' => $zone->id, 'number' => 1]);
    $this->delete(route('club-map.destroy', $zone))->assertRedirect();
    $deleted = latestLog('zone.deleted');
    expect($deleted->user_id)->toBe($user->id)
        ->and($deleted->details)->toBe(['computers_removed' => 1]);

    $this->get(route('activity-logs.index'))->assertSuccessful()->assertSee('name: VIP → VIP Premium');
});

test('adding, registering and controlling PCs is logged', function () {
    $user = User::factory()->create();
    $zone = ClubZone::query()->create(['name' => 'Основной зал']);

    $this->actingAs($user)->post(route('club-computers.store', $zone))->assertRedirect();
    $computer = ClubComputer::query()->firstOrFail();
    expect(latestLog('computer.created')->subject_id)->toBe($computer->id);

    $this->postJson(route('club-computers.control'), ['computer_ids' => [$computer->id], 'action' => 'restart'])->assertSuccessful();
    expect(latestLog('computer.controlled')->details)->toBe(['action' => 'restart', 'computers' => 'PC-01', 'updated' => 1]);
    $this->postJson(route('club-computers.control'), ['computer_ids' => [$computer->id], 'action' => 'reserve'])->assertSuccessful();
    expect(latestLog('computer.controlled')->details['action'])->toBe('reserve');

    $token = $user->createToken('agent', ['club:'.$zone->club_id])->plainTextToken;
    $this->withToken($token)->postJson(route('api.club-computers.register'), ['device_name' => 'DESKTOP-NEW', 'device_id' => 'machine-42'])
        ->assertCreated();
    $registered = latestLog('computer.registered');
    expect($registered->user_id)->toBe($user->id)
        ->and($registered->details)->toMatchArray(['device_name' => 'DESKTOP-NEW', 'device_id' => 'machine-42']);

    $this->withToken($token)->postJson(route('api.club-computers.register'), ['device_name' => 'DESKTOP-NEW', 'device_id' => 'machine-42'])
        ->assertSuccessful();
    expect(ActivityLog::query()->where('event', 'computer.registered')->count())->toBe(1);
});
