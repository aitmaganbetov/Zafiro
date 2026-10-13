<?php

use App\Models\ActivityLog;
use App\Models\Club;
use App\Models\ClubInvitation;
use App\Models\User;
use App\Services\CurrentClub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

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
