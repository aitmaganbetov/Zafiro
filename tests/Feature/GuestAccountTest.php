<?php

use App\Models\ActivityLog;
use App\Models\ClubMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

function guestWithRole(string $role, array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    ClubMembership::query()->where('user_id', $user->id)->update(['role' => $role]);

    return $user;
}

function membershipOf(User $user): ClubMembership
{
    return ClubMembership::query()->where('user_id', $user->id)->sole();
}

test('a cashier edits the name and email of a customer, not the discount', function () {
    $cashier = guestWithRole('cashier');
    $guest = guestWithRole('client', ['name' => 'Арман', 'email' => 'old@example.com']);

    $this->actingAs($cashier)->get(route('users.index'))->assertOk()->assertSee('Изменить')->assertSee('Сбросить пароль');
    $this->patch(route('users.update', membershipOf($guest)), ['name' => 'Арман Сейтов', 'email' => 'new@example.com'])->assertSessionHasNoErrors();
    $this->patch(route('users.update', membershipOf($guest)), ['name' => 'x', 'discount' => 50])->assertSessionHasErrors('discount');

    expect($guest->fresh())->name->toBe('Арман Сейтов')->email->toBe('new@example.com')
        ->and(ActivityLog::query()->where('event', 'user.updated')->exists())->toBeTrue();
});

test('staff accounts are not edited or reset from the customer list', function () {
    $owner = User::factory()->create();
    $cashier = guestWithRole('cashier');

    $this->actingAs($owner)->patch(route('users.update', membershipOf($cashier)), ['name' => 'x'])->assertForbidden();
    $this->post(route('users.password', membershipOf($cashier)))->assertForbidden();
});

test('a reset password is shown once to the staff and the customer is signed out', function () {
    $cashier = guestWithRole('cashier');
    $guest = guestWithRole('client', ['password' => bcrypt('old-secret')]);
    $guest->createToken('PC');

    $response = $this->actingAs($cashier)->post(route('users.password', membershipOf($guest)))->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertSee('Передайте клиенту новый пароль');
    preg_match('/tracking-widest[^>]*>([A-Za-z0-9]{8})</', $response->getContent(), $match);

    expect($match[1] ?? null)->not->toBeNull()
        ->and(Hash::check($match[1], $guest->fresh()->password))->toBeTrue()
        ->and($guest->tokens()->count())->toBe(0)
        ->and(ActivityLog::query()->where('event', 'user.password_reset')->sole()->details)->toBe(['delivery' => 'screen']);
    expect(session()->all())->not->toContain($match[1]);
});
