<?php

use App\Models\Club;
use App\Models\ClubSubscription;
use App\Models\User;
use App\Services\CurrentClub;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('new clubs start with the configured trial plan', function () {
    $this->post(route('auth.register'), [
        'name' => 'Owner',
        'club_name' => 'Trial Club',
        'phone' => '7001234567',
        'password' => 'secret123',
        'password_confirmation' => 'secret123',
    ])->assertRedirect('/dashboard');

    $owner = User::query()->where('phone', '+77001234567')->firstOrFail();
    $subscription = $owner->currentMembership()->firstOrFail()->club->subscription()->with('plan')->firstOrFail();

    expect($subscription->status)->toBe('trialing')
        ->and($subscription->plan->slug)->toBe('trial')
        ->and($subscription->trial_ends_at->isFuture())->toBeTrue();

    $this->get(route('dashboard'))->assertSuccessful();
});

test('expired clubs are sent to billing and cannot issue api tokens', function () {
    $club = Club::query()->create(['name' => 'Expired Club', 'slug' => 'expired-club']);
    app(CurrentClub::class)->set($club);
    $user = User::factory()->create(['phone' => '+77001234567']);
    $plan = App\Models\SaasPlan::query()->firstOrCreate(
        ['slug' => 'trial'],
        ['name' => 'Пробный период', 'trial_days' => 14, 'is_active' => true],
    );
    $club->subscription()->create([
        'saas_plan_id' => $plan->id,
        'status' => 'trialing',
        'started_at' => now()->subDays(15),
        'trial_ends_at' => now()->subMinute(),
    ]);

    $this->actingAs($user)
        ->withSession(['current_club_id' => $club->id])
        ->get(route('dashboard'))
        ->assertRedirect(route('billing.show'));

    $this->get(route('billing.show'))->assertSuccessful()->assertSee('Expired Club');

    $this->postJson('/api/auth/login', [
        'phone' => '+77001234567',
        'password' => 'password',
        'club_id' => $club->id,
    ])->assertStatus(402);
});
