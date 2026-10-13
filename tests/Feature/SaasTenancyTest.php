<?php

use App\Models\Club;
use App\Models\ClubMembership;
use App\Models\Product;
use App\Models\SaasPlan;
use App\Models\User;
use App\Services\CurrentClub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('registration creates a club owner and keeps balances outside users', function () {
    $this->post(route('auth.register'), [
        'name' => 'Владелец',
        'club_name' => 'Новый клуб',
        'phone' => '7001234567',
        'password' => 'secret123',
        'password_confirmation' => 'secret123',
    ])->assertRedirect('/dashboard');

    $user = User::query()->where('phone', '+77001234567')->firstOrFail();
    $membership = ClubMembership::query()->withoutGlobalScope('club')
        ->where('user_id', $user->id)
        ->firstOrFail();

    expect($membership->role)->toBe('owner')
        ->and($membership->balance->balance)->toBe('0.00')
        ->and(Schema::hasColumn('users', 'balance'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'bonus_balance'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'role'))->toBeFalse();
});

test('tenant data and membership access are isolated by club', function () {
    $currentClub = app(CurrentClub::class);
    $clubA = $currentClub->get();
    $userA = User::factory()->create();
    Product::factory()->create(['sku' => 'SHARED-001', 'name' => 'Товар клуба A']);

    $clubB = Club::query()->create(['name' => 'Клуб B', 'slug' => 'club-b']);
    $currentClub->set($clubB);
    $userB = User::factory()->create();
    $plan = SaasPlan::query()->firstOrCreate(
        ['slug' => 'trial'],
        ['name' => 'Пробный период', 'trial_days' => 14, 'is_active' => true],
    );
    $clubB->subscription()->create([
        'saas_plan_id' => $plan->id,
        'status' => 'active',
        'started_at' => now(),
    ]);
    Product::factory()->create(['sku' => 'SHARED-001', 'name' => 'Товар клуба B']);

    $this->actingAs($userB)
        ->withSession(['current_club_id' => $clubB->id])
        ->get('/warehouse')
        ->assertSuccessful()
        ->assertSee('Товар клуба B')
        ->assertDontSee('Товар клуба A');

    $this->actingAs($userA)
        ->withSession(['current_club_id' => $clubB->id])
        ->get('/warehouse')
        ->assertForbidden();

    expect(Product::query()->count())->toBe(1);
    $currentClub->set($clubA);
});

test('club-bound api tokens cannot switch clubs with a header', function () {
    $clubA = app(CurrentClub::class)->get();
    $clubB = Club::query()->create(['name' => 'Клуб B', 'slug' => 'club-b']);
    app(CurrentClub::class)->set($clubB);
    $user = User::factory()->create();

    $clubA->memberships()->create(['user_id' => $user->id, 'role' => 'Пользователь']);
    $token = $user->createToken('club-b-device', ['club:'.$clubB->id])->plainTextToken;

    $this->withToken($token)
        ->withHeader('X-Club-Id', (string) $clubA->id)
        ->getJson('/api/products')
        ->assertForbidden();
});
