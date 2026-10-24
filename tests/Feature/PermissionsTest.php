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

/** The rights form as sent: every right of the employee as it is now, with some changed. */
function rightsForm(ClubMembership $membership, array $changes): array
{
    $rights = [];
    foreach (ClubPermissions::all() as $right) {
        [$section, $action] = explode('.', $right);
        $rights[$section][$action] = array_key_exists($right, $changes) ? ($changes[$right] ? '1' : '0') : ($membership->hasPermission($right) ? '1' : '0');
    }

    return ['rights' => $rights];
}

test('by default every role keeps the rights it had', function () {
    $owner = User::factory()->create();
    [, $admin] = rightsMember('admin', 'Админ');
    [, $manager] = rightsMember('manager', 'Менеджер');
    [, $cashier] = rightsMember('cashier', 'Кассир');
    [, $operator] = rightsMember('operator', 'Оператор');

    expect(ClubMembership::query()->where('user_id', $owner->id)->sole()->hasPermission('tariffs.delete'))->toBeTrue()
        ->and($admin->hasPermission('workers.update'))->toBeTrue()
        ->and($admin->hasPermission('tariffs.delete'))->toBeFalse()
        ->and($manager->hasPermission('workers.view'))->toBeFalse()
        ->and($manager->hasPermission('warehouse.create'))->toBeTrue()
        ->and($cashier->hasPermission('cash.create'))->toBeTrue()
        ->and($cashier->hasPermission('cash.delete'))->toBeFalse()
        ->and($cashier->hasPermission('customers.update'))->toBeTrue()
        ->and($operator->hasPermission('cash.view'))->toBeFalse()
        ->and($operator->hasPermission('chat.create'))->toBeTrue()
        ->and($manager->hasPermission('chat.create'))->toBeFalse();
});

test('pick an employee, then tick rights: a cashier may create products but not see the cash register', function () {
    $owner = User::factory()->create();
    [$cashierUser, $cashier] = rightsMember('cashier', 'Айгерим');

    $this->actingAs($owner)->get(route('settings.permissions'))->assertOk()
        ->assertSee('Айгерим')->assertSee(route('settings.permissions.edit', $cashier), false);
    $this->get(route('settings.permissions.edit', $cashier))->assertOk()
        ->assertSee('Права: Айгерим')->assertSeeInOrder(['Просмотр', 'Создание', 'Изменение', 'Удаление'])
        ->assertSee('name="rights[warehouse][create]" value="1"', false)
        ->assertDontSee('name="rights[stock][delete]"', false);

    $this->put(route('settings.permissions.update', $cashier), rightsForm($cashier, ['warehouse.view' => true, 'warehouse.create' => true, 'cash.view' => false]))
        ->assertRedirect(route('settings.permissions.edit', $cashier))->assertSessionHas('status', 'Права сохранены.');

    expect($cashier->fresh()->permissions)->toBe(['cash.view' => false, 'warehouse.view' => true, 'warehouse.create' => true])
        ->and(ActivityLog::query()->where('event', 'permissions.updated')->sole()->details)
        ->toBe(['Касса: Просмотр' => 'нет', 'Склад: каталог: Просмотр' => 'да', 'Склад: каталог: Создание' => 'да']);

    $this->actingAs($cashierUser)->get(route('warehouse'))->assertOk();
    $this->get(route('cash-register.index'))->assertForbidden();
    $this->post(route('warehouse.categories.store'), ['name' => 'Снеки'])->assertSessionHasNoErrors();
    $this->post(route('warehouse.stock-movements.store'), [])->assertForbidden();

    $this->actingAs($owner)->post(route('settings.permissions.reset', $cashier))->assertRedirect();
    expect($cashier->fresh()->permissions)->toBeNull();
});

test('an admin sets the rights of a cashier, not their own and not of other admins', function () {
    User::factory()->create(); // the owner
    [$adminUser, $admin] = rightsMember('admin', 'Админ');
    [, $other] = rightsMember('admin', 'Второй админ');
    [, $cashier] = rightsMember('cashier', 'Кассир');

    $this->actingAs($adminUser)->get(route('settings.permissions'))->assertOk()
        ->assertSee(route('settings.permissions.edit', $cashier), false)->assertDontSee(route('settings.permissions.edit', $other), false);
    $this->get(route('settings.permissions.edit', $cashier))->assertOk();
    $this->get(route('settings.permissions.edit', $admin))->assertForbidden();
    $this->put(route('settings.permissions.update', $other), [])->assertForbidden();
});

test('managers and the front desk do not open the rights page', function () {
    User::factory()->create();
    [$managerUser] = rightsMember('manager', 'Менеджер');

    $this->actingAs($managerUser)->get(route('settings.permissions'))->assertForbidden();
});

test('without a right the page and its buttons are not there', function () {
    User::factory()->create();
    [$cashierUser, $cashier] = rightsMember('cashier', 'Кассир');
    $cashier->forceFill(['permissions' => ['customers.create' => false, 'reservations.view' => false]])->save();

    $this->actingAs($cashierUser)->get(route('users.index'))->assertOk()->assertDontSee('id="open-user-dialog"', false);
    $this->post(route('users.store'), [])->assertForbidden();
    $this->get(route('reservations.index'))->assertForbidden();
    $this->get(route('club-map.index'))->assertOk()->assertDontSee('<span>Брони</span>', false);
});

test('every route of the guarded groups has a right, and every right exists', function () {
    $missing = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array('club-area', $route->gatherMiddleware(), true))
        ->map(fn ($route) => $route->getName())
        ->filter(fn (?string $name) => RequireClubArea::rightFor($name) === null)
        ->values()->all();

    expect($missing)->toBe([])
        ->and(collect(array_keys(RequireClubArea::ROUTES))->reject(fn (string $right) => ClubPermissions::exists($right))->all())->toBe([]);
});
