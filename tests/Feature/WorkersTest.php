<?php

use App\Models\Club;
use App\Models\ClubMembership;
use App\Models\User;
use App\Services\CurrentClub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function setRole(User $user, string $role): void
{
    ClubMembership::query()->withoutGlobalScope('club')->where('user_id', $user->id)->update(['role' => $role]);
}

test('the owner adds a registered user as a worker by phone, changes the role and removes them', function () {
    $owner = User::factory()->create();
    $person = User::factory()->create(['name' => 'Дана', 'phone' => '+77029998877']);
    setRole($person, 'client');
    $stranger = User::factory()->create(['phone' => '+77011112233']);
    ClubMembership::query()->where('user_id', $stranger->id)->delete();

    $this->actingAs($owner)->get(route('workers.index'))->assertOk()->assertSee('Работники');

    $this->post(route('workers.store'), ['phone' => '8 702 999 88 77', 'role' => 'cashier'])->assertSessionHasNoErrors();
    $this->post(route('workers.store'), ['phone' => '+77011112233', 'role' => 'operator'])->assertSessionHasNoErrors();
    $this->post(route('workers.store'), ['phone' => '+77000000000', 'role' => 'operator'])->assertSessionHasErrors('phone');
    $this->post(route('workers.store'), ['phone' => '+77029998877', 'role' => 'operator'])->assertSessionHasErrors('phone');

    $dana = ClubMembership::query()->where('user_id', $person->id)->sole();
    expect($dana->role)->toBe('cashier')
        ->and(ClubMembership::query()->where('user_id', $stranger->id)->sole()->role)->toBe('operator');

    $this->patch(route('workers.update', $dana), ['role' => 'manager'])->assertSessionHasNoErrors();
    expect($dana->fresh()->role)->toBe('manager');

    $this->delete(route('workers.destroy', $dana))->assertSessionHasNoErrors();
    expect($dana->fresh()->role)->toBe('client');
    $this->get(route('users.index'))->assertSee('Дана');
});

test('an admin cannot touch administrators, the owner or their own role', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $otherAdmin = User::factory()->create();
    setRole($admin, 'admin');
    setRole($otherAdmin, 'admin');
    $memberships = fn (User $user) => ClubMembership::query()->where('user_id', $user->id)->sole();

    $this->actingAs($admin);
    $this->patch(route('workers.update', $memberships($otherAdmin)), ['role' => 'cashier'])->assertForbidden();
    $this->patch(route('workers.update', $memberships($admin)), ['role' => 'cashier'])->assertForbidden();
    $this->delete(route('workers.destroy', $memberships($owner)))->assertNotFound();
    $this->post(route('workers.store'), ['phone' => User::factory()->create()->phone, 'role' => 'admin'])->assertSessionHasErrors('role');

    setRole($admin, 'manager');
    $this->get(route('workers.index'))->assertForbidden();
});

test('customers cannot open the club panel; they sign in on the site only to confirm a PC sign-in', function () {
    $customer = User::factory()->create(['phone' => '+77011112233', 'password' => bcrypt('secret-1')]);
    setRole($customer, 'client');

    $this->post(route('auth.login'), ['phone' => '+77011112233', 'password' => 'secret-1'])
        ->assertSessionHasErrors('phone');
    $this->assertGuest();

    $this->withSession(['url.intended' => url('/pc-login/'.str_repeat('a', 40))])
        ->post(route('auth.login'), ['phone' => '+77011112233', 'password' => 'secret-1'])
        ->assertRedirect(url('/pc-login/'.str_repeat('a', 40)));
    $this->assertAuthenticatedAs($customer);
    $this->get(route('dashboard'))->assertForbidden();
    $this->get(route('billing.show'))->assertForbidden();
});

test('only as many cashiers as the club allows can be signed in at once', function () {
    config(['session.driver' => 'database']);
    $club = Club::query()->findOrFail(app(CurrentClub::class)->id());
    $club->forceFill(['settings' => ['club' => ['max_cashiers_on_shift' => 1]]])->save();
    $first = User::factory()->create(['phone' => '+77011111111', 'password' => bcrypt('secret-1')]);
    $second = User::factory()->create(['phone' => '+77022222222', 'password' => bcrypt('secret-1')]);
    setRole($first, 'cashier');
    setRole($second, 'cashier');
    app(CurrentClub::class)->clear();

    // The first cashier is on shift: a live session.
    DB::table('sessions')->insert(['id' => 'live', 'user_id' => $first->id, 'payload' => '', 'last_activity' => now()->getTimestamp()]);

    $this->post(route('auth.login'), ['phone' => '+77022222222', 'password' => 'secret-1'])->assertSessionHasErrors('phone');
    $this->assertGuest();

    // The first one signed out (or the session expired): now there is a place.
    DB::table('sessions')->where('id', 'live')->update(['last_activity' => now()->subHours(3)->getTimestamp()]);
    $this->post(route('auth.login'), ['phone' => '+77022222222', 'password' => 'secret-1'])->assertRedirect('/dashboard');
    $this->assertAuthenticatedAs($second);
});
