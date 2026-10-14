<?php

use App\Http\Middleware\SetCurrentClub;
use App\Models\ActivityLog;
use App\Models\Club;
use App\Models\ClubMembership;
use App\Models\ClubSubscription;
use App\Models\PlatformLog;
use App\Models\SaasPlan;
use App\Models\Tariff;
use App\Models\User;
use App\Services\CurrentClub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/** A platform superadmin who is not a member of any club. */
function cabinetAdmin(): User
{
    $user = User::factory()->superadmin()->create(['phone' => '+77009990001', 'password' => Hash::make('secret-pass')]);
    ClubMembership::query()->withoutGlobalScope('club')->where('user_id', $user->id)->delete();

    return $user;
}

function cabinetClub(): Club
{
    return Club::query()->findOrFail(app(CurrentClub::class)->id());
}

test('only superadmins open the cabinet', function () {
    $pages = ['superadmin.dashboard', 'superadmin.clubs.index', 'superadmin.users.index', 'superadmin.plans.index', 'superadmin.logs.index', 'superadmin.languages'];

    foreach ($pages as $page) {
        $this->get(route($page))->assertRedirect(route('auth.show-login'));
    }

    $owner = User::factory()->create();
    foreach ($pages as $page) {
        $this->actingAs($owner)->get(route($page))->assertForbidden();
    }
    $this->post(route('superadmin.clubs.view', cabinetClub()))->assertForbidden();

    $admin = cabinetAdmin();
    foreach ($pages as $page) {
        $this->actingAs($admin)->get(route($page))->assertOk();
    }
    $this->get(route('superadmin.clubs.show', cabinetClub()))->assertOk()->assertSee(cabinetClub()->name);
    $this->get(route('superadmin.logs.index', ['tab' => 'clubs']))->assertOk();
});

test('a superadmin without a club signs in to the cabinet, club pages send them there', function () {
    cabinetAdmin();
    app(CurrentClub::class)->clear();

    $this->post(route('auth.login'), ['phone' => '+77009990001', 'password' => 'secret-pass'])
        ->assertRedirect(route('superadmin.dashboard'));
    $this->get(route('dashboard'))->assertRedirect(route('superadmin.dashboard'));
    expect(PlatformLog::query()->where('event', 'superadmin.login')->exists())->toBeTrue();
});

test('the superadmin extends, suspends and moves a club to another plan', function () {
    $admin = cabinetAdmin();
    $club = cabinetClub();
    $plan = SaasPlan::query()->create(['slug' => 'pro', 'name' => 'Pro', 'price_monthly' => 50000, 'trial_days' => 0, 'is_active' => true]);
    $this->travelTo(now()->startOfDay());

    $this->actingAs($admin)->post(route('superadmin.clubs.extend', $club), ['days' => 0])->assertSessionHasErrors('days');
    $this->post(route('superadmin.clubs.extend', $club), ['days' => 30])->assertSessionHasNoErrors();
    $subscription = ClubSubscription::query()->where('club_id', $club->id)->sole();
    expect($subscription->status)->toBe('active')
        ->and($subscription->current_period_ends_at->toDateString())->toBe(now()->addDays(30)->toDateString());

    // Extending again adds to the paid period, not to today.
    $this->post(route('superadmin.clubs.extend', $club), ['days' => 10]);
    expect($subscription->fresh()->current_period_ends_at->toDateString())->toBe(now()->addDays(40)->toDateString());

    $this->post(route('superadmin.clubs.plan', $club), ['saas_plan_id' => $plan->id])->assertSessionHasNoErrors();
    expect($subscription->fresh()->saas_plan_id)->toBe($plan->id);

    $this->post(route('superadmin.clubs.suspend', $club))->assertSessionHasNoErrors();
    expect($subscription->fresh()->isActive())->toBeFalse();

    // The club's own staff now land on the billing page.
    $owner = User::factory()->create();
    $this->actingAs($owner)->get(route('dashboard'))->assertRedirect(route('billing.show'));

    expect(PlatformLog::query()->where('club_id', $club->id)->pluck('event')->all())
        ->toContain('superadmin.subscription_extended', 'superadmin.subscription_plan_changed', 'superadmin.subscription_suspended');
});

test('a blocked user is signed out everywhere and cannot sign in again', function () {
    $admin = cabinetAdmin();
    $user = User::factory()->create(['phone' => '+77009990002', 'password' => Hash::make('secret-pass')]);
    $apiToken = $user->createToken('PC-1', ['club:'.cabinetClub()->id])->plainTextToken;
    DB::table('sessions')->insert(['id' => 'live-session', 'user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'x', 'payload' => 'x', 'last_activity' => now()->timestamp]);

    $this->actingAs($admin)->post(route('superadmin.users.block', $user))->assertSessionHasNoErrors();

    expect($user->fresh()->isBlocked())->toBeTrue()
        ->and($user->tokens()->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse();

    // A session that is still open is closed on the next request.
    $this->actingAs($user->fresh())->get(route('dashboard'))->assertRedirect(route('auth.show-login'));
    $this->assertGuest();

    $this->post(route('auth.login'), ['phone' => '+77009990002', 'password' => 'secret-pass'])->assertSessionHasErrors('phone');
    $this->assertGuest();
    $this->postJson(route('api.agent.user.login'), ['phone' => '+77009990002', 'password' => 'secret-pass', 'device_name' => 'PC-1'])
        ->assertForbidden()->assertJsonPath('code', 'account_blocked');
    $this->withToken($apiToken)->getJson(route('api.me'))->assertUnauthorized();

    $this->actingAs($admin)->post(route('superadmin.users.unblock', $user));
    expect($user->fresh()->isBlocked())->toBeFalse();
});

test('superadmins cannot lock themselves out, and a superadmin must lose the role before being blocked', function () {
    $admin = cabinetAdmin();
    $other = User::factory()->create();

    $this->actingAs($admin)->post(route('superadmin.users.block', $admin))->assertSessionHasErrors('user');
    $this->delete(route('superadmin.users.revoke', $admin))->assertSessionHasErrors('user');

    $this->post(route('superadmin.users.grant', $other))->assertSessionHasNoErrors();
    expect($other->fresh()->isSuperadmin())->toBeTrue();
    $this->post(route('superadmin.users.block', $other))->assertSessionHasErrors('user');
    $this->delete(route('superadmin.users.revoke', $other))->assertSessionHasNoErrors();
    expect($other->fresh()->isSuperadmin())->toBeFalse()
        ->and(PlatformLog::query()->whereIn('event', ['superadmin.granted', 'superadmin.revoked'])->count())->toBe(2);
});

test('the superadmin creates and edits platform plans', function () {
    $admin = cabinetAdmin();

    $this->actingAs($admin)->post(route('superadmin.plans.store'), ['slug' => 'bad slug', 'name' => 'X', 'trial_days' => 0])->assertSessionHasErrors('slug');
    $this->post(route('superadmin.plans.store'), [
        'slug' => 'start', 'name' => 'Старт', 'price_monthly' => 15000, 'trial_days' => 14,
        'features' => "До 20 ПК\n\nАгент Windows", 'is_active' => 1,
    ])->assertSessionHasNoErrors();
    $plan = SaasPlan::query()->where('slug', 'start')->sole();
    expect($plan->features)->toBe(['До 20 ПК', 'Агент Windows'])->and($plan->is_active)->toBeTrue();

    $this->patch(route('superadmin.plans.update', $plan), ['slug' => 'start', 'name' => 'Старт', 'price_monthly' => 17000, 'trial_days' => 7])->assertSessionHasNoErrors();
    expect($plan->fresh())->is_active->toBeFalse()->trial_days->toBe(7)
        ->and((float) $plan->fresh()->price_monthly)->toBe(17000.0);
});

test('a superadmin views a club read-only and the club log shows it', function () {
    $admin = cabinetAdmin();
    $club = cabinetClub();

    $this->actingAs($admin)->post(route('superadmin.clubs.view', $club))->assertRedirect(route('dashboard'));
    $this->get(route('dashboard'))->assertOk()->assertSee(__('Вернуться в кабинет'));

    // Every page of the club opens without errors.
    $pages = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array('GET', $route->methods(), true)
            && in_array('current-club', $route->gatherMiddleware(), true)
            && ! str_starts_with($route->uri(), 'api/')
            && $route->parameterNames() === []);
    expect($pages)->not->toBeEmpty();
    foreach ($pages as $route) {
        $status = $this->get('/'.$route->uri())->status();
        expect($status)->toBeLessThan(500, "GET /{$route->uri()} returned {$status}");
    }

    // Nothing can be changed.
    $this->post(route('tariffs.store'), ['name' => 'Взлом', 'price_per_hour' => 1])->assertForbidden();
    $this->post(route('clubs.switch'), ['club_id' => $club->id])->assertForbidden();
    expect(Tariff::query()->where('name', 'Взлом')->exists())->toBeFalse()
        ->and(ActivityLog::query()->where('event', 'superadmin.club_viewed')->where('user_id', $admin->id)->exists())->toBeTrue();

    $this->post(route('superadmin.clubs.leave'))->assertRedirect(route('superadmin.clubs.show', $club));
    expect(session()->has(SetCurrentClub::VIEWING_KEY))->toBeFalse();
    $this->get(route('dashboard'))->assertRedirect(route('superadmin.dashboard'));
});

test('a paid subscription ends at its paid date and the scheduler marks it expired', function () {
    $admin = cabinetAdmin();
    $club = cabinetClub();
    $subscription = ClubSubscription::query()->where('club_id', $club->id)->sole();
    $subscription->forceFill(['status' => 'active', 'current_period_ends_at' => now()->addDay()])->save();
    $owner = User::factory()->create();

    $this->actingAs($owner)->get(route('dashboard'))->assertOk();

    // Access ends at the paid date, before the scheduler runs.
    $this->travel(2)->days();
    expect($subscription->fresh()->isActive())->toBeFalse();
    $this->get(route('dashboard'))->assertRedirect(route('billing.show'));

    $this->artisan('subscriptions:expire')->expectsOutput('expired: 1')->assertSuccessful();
    expect($subscription->fresh()->status)->toBe('expired')
        ->and(PlatformLog::query()->where('event', 'subscription.expired')->where('club_id', $club->id)->whereNull('user_id')->exists())->toBeTrue();
    $this->get(route('billing.show'))->assertOk()->assertSee(__('Истекла'));
    $this->artisan('subscriptions:expire')->expectsOutput('expired: 0');

    // Extending an expired subscription counts from today and opens the club again.
    $this->actingAs($admin)->post(route('superadmin.clubs.extend', $club), ['days' => 30]);
    expect($subscription->fresh())->status->toBe('active')
        ->and($subscription->fresh()->current_period_ends_at->utc()->toDateString())->toBe(now()->utc()->addDays(30)->toDateString());
    $this->actingAs($owner)->get(route('dashboard'))->assertOk();
});

test('a subscription without a paid date does not expire', function () {
    $subscription = ClubSubscription::query()->where('club_id', cabinetClub()->id)->sole();
    $subscription->forceFill(['status' => 'active', 'current_period_ends_at' => null])->save();
    $this->travel(400)->days();

    $this->artisan('subscriptions:expire')->expectsOutput('expired: 0');
    expect($subscription->fresh()->isActive())->toBeTrue()
        ->and(ClubSubscription::query()->active()->whereKey($subscription->id)->exists())->toBeTrue();
});
