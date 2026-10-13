<?php

use App\Models\ClubInvitation;
use App\Models\ClubMembership;
use App\Models\MemberBalance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('club owners can invite a new account with an expiring one-time link', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)->post(route('club-invitations.store'), [
        'email' => 'operator@example.com',
        'role' => 'operator',
    ])->assertRedirect(route('users.index'))
        ->assertSessionHas('invite_url');

    $inviteUrl = session('invite_url');
    $token = basename(parse_url($inviteUrl, PHP_URL_PATH));
    $invitation = ClubInvitation::query()->firstOrFail();

    expect($invitation->token_hash)->toBe(hash('sha256', $token))
        ->and($invitation->expires_at->isFuture())->toBeTrue();
});

test('invitee can create an account and join the invited club', function () {
    $token = str()->random(64);
    $invitation = ClubInvitation::query()->create([
        'email' => 'operator@example.com',
        'role' => 'operator',
        'token_hash' => hash('sha256', $token),
        'expires_at' => now()->addDays(7),
    ]);

    $this->get(route('club-invitations.show', $token))
        ->assertSuccessful()
        ->assertSee('operator@example.com');

    $this->post(route('club-invitations.accept', $token), [
        'name' => 'Оператор клуба',
        'phone' => '7001234567',
        'password' => 'member-secret',
        'password_confirmation' => 'member-secret',
    ])->assertRedirect(route('dashboard'));

    $member = User::query()->where('phone', '+77001234567')->firstOrFail();
    $membership = ClubMembership::query()->withoutGlobalScope('club')
        ->where('club_id', $invitation->club_id)
        ->where('user_id', $member->id)
        ->firstOrFail();

    expect($membership->role)->toBe('operator')
        ->and(MemberBalance::query()->withoutGlobalScope('club')->where('club_id', $invitation->club_id)->where('user_id', $member->id)->exists())->toBeTrue()
        ->and($invitation->refresh()->accepted_at)->not->toBeNull();

    $this->get(route('club-invitations.show', $token))->assertNotFound();
});

test('managers cannot invite administrators', function () {
    $manager = User::factory()->create();
    $manager->currentMembership()->firstOrFail()->update(['role' => 'manager']);

    $this->actingAs($manager)->post(route('club-invitations.store'), [
        'role' => 'admin',
    ])->assertForbidden();

    expect(ClubInvitation::query()->count())->toBe(0);
});
