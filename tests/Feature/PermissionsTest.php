<?php

use App\Http\Middleware\RequireClubArea;
use App\Models\ActivityLog;
use App\Models\ClubMembership;
use App\Models\User;
use App\Support\ClubPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function rightsMember(string $role, string $name): array
{
    $user = User::factory()->create(['name' => $name]);
    $membership = ClubMembership::query()->where('user_id', $user->id)->sole();
    $membership->update(['role' => $role]);

    return [$user, $membership];
}

test('by default every role keeps the rights it had', function () {
    $owner = User::factory()->create();
    [, $admin] = rightsMember('admin', 'Админ');
    [, $manager] = rightsMember('manager', 'Менеджер');
    [, $cashier] = rightsMember('cashier', 'Кассир');
    [, $operator] = rightsMember('operator', 'Оператор');

    expect(ClubPermissions::allows(ClubMembership::query()->where('user_id', $owner->id)->sole(), 'workers'))->toBeTrue()
        ->and($admin->hasPermission('workers'))->toBeTrue()
        ->and($manager->hasPermission('workers'))->toBeFalse()
        ->and($manager->hasPermission('tariffs'))->toBeTrue()
        ->and($cashier->hasPermission('cash_register'))->toBeTrue()
        ->and($cashier->hasPermission('cash_refunds'))->toBeFalse()
        ->and($operator->hasPermission('cash_register'))->toBeFalse();
});

test('the owner gives a cashier the tariffs and takes the cash register away', function () {
    $owner = User::factory()->create();
    [$cashierUser, $cashier] = rightsMember('cashier', 'Айгерим');
    [, $manager] = rightsMember('manager', 'Данияр');

    $this->actingAs($owner)->get(route('settings.permissions'))->assertOk()
        ->assertSee('Права сотрудников')->assertSee('Айгерим')->assertSee('Данияр')
        ->assertSee('name="rights['.$cashier->id.'][cash_register]" value="1" aria-label="Касса: Айгерим" checked', false);

    $form = ['rights' => [
        $cashier->id => array_merge(array_fill_keys(array_keys(ClubPermissions::DEFAULTS), '0'), ['tariffs' => '1']),
        $manager->id => array_fill_keys(array_keys(ClubPermissions::DEFAULTS), '0') + [],
    ]];
    foreach (ClubPermissions::DEFAULTS as $right => $roles) {
        $form['rights'][$manager->id][$right] = in_array('manager', $roles, true) ? '1' : '0';
    }
    $this->post(route('settings.permissions.update'), $form)->assertRedirect(route('settings.permissions'))->assertSessionHas('status', 'Права сохранены.');

    // Only the differences from the role are stored; the manager stays as the role.
    expect($cashier->fresh()->permissions)->toBe(['cash_register' => false, 'tariffs' => true])
        ->and($manager->fresh()->permissions)->toBeNull()
        ->and(ActivityLog::query()->where('event', 'permissions.updated')->sole()->details)->toBe(['Касса' => 'нет', 'Тарифы' => 'да']);

    $this->actingAs($cashierUser)->get(route('tariffs.index'))->assertOk();
    $this->get(route('cash-register.index'))->assertForbidden();
    $this->get(route('dashboard'))->assertRedirect();
    $this->actingAs($cashierUser)->get(route('club-computers.index'))->assertOk()
        ->assertSee(route('tariffs.index'), false)->assertDontSee('<span>Касса</span>', false);

    $this->actingAs($owner)->post(route('settings.permissions.reset', $cashier))->assertRedirect();
    expect($cashier->fresh()->permissions)->toBeNull();
});

test('only the owner opens the rights page', function () {
    User::factory()->create();
    [$adminUser] = rightsMember('admin', 'Админ');

    $this->actingAs($adminUser)->get(route('settings.permissions'))->assertForbidden();
    $this->post(route('settings.permissions.update'), [])->assertForbidden();
    $this->get(route('settings.club'))->assertOk()->assertDontSee(route('settings.permissions'), false);
});

test('a manager without the settings right does not open the settings', function () {
    User::factory()->create();
    [$managerUser, $manager] = rightsMember('manager', 'Менеджер');
    $manager->forceFill(['permissions' => ['settings' => false]])->save();

    $this->actingAs($managerUser)->get(route('settings.club'))->assertForbidden();
    $this->post(route('settings.save'), ['section' => 'club'])->assertForbidden();
    $this->get(route('tariffs.index'))->assertOk();
});

test('every route of the guarded groups has a right', function () {
    $missing = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array('club-area', $route->gatherMiddleware(), true))
        ->map(fn ($route) => $route->getName())
        ->filter(fn (?string $name) => RequireClubArea::rightFor($name) === null)
        ->values()->all();

    expect($missing)->toBe([]);
});
