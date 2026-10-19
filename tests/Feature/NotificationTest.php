<?php

use App\Models\ActivityLog;
use App\Models\ClubComputer;
use App\Models\ClubInvitation;
use App\Models\ClubMembership;
use App\Models\ClubSubscription;
use App\Models\ClubZone;
use App\Models\Product;
use App\Models\User;
use App\Services\CurrentClub;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function logEvent(User $user, string $event, string $description, ?string $at = null): ActivityLog
{
    $log = ActivityLog::query()->create(['event' => $event, 'description' => $description, 'user_id' => $user->id, 'ip_address' => '10.0.0.5']);

    if ($at !== null) {
        $log->created_at = $at;
        $log->saveQuietly();
    }

    return $log;
}

function memberWithRole(string $role): User
{
    $user = User::factory()->create();
    ClubMembership::query()->where('user_id', $user->id)->update(['role' => $role]);

    return $user;
}

test('guests cannot read notifications', function () {
    $this->get(route('notifications.index'))->assertRedirect(route('auth.show-login'));
    $this->post(route('notifications.seen'))->assertRedirect(route('auth.show-login'));
});

test('managers see activity, stock, offline PCs, subscription and invitation notifications', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create(['name' => 'Кассир Айгерим']);

    logEvent($other, 'auth.login_failed', 'Неудачный вход: Кассир Айгерим');
    logEvent($owner, 'settings.updated', 'Мои собственные настройки');
    logEvent($other, 'zone.deleted', 'Старое удаление', now()->subDays(8)->toDateTimeString());
    logEvent($other, 'cash.income', 'Обычная операция кассы');

    Product::factory()->create(['name' => 'Red Bull', 'quantity' => 2]);
    Product::factory()->create(['name' => 'Вода', 'quantity' => 40]);

    $zone = ClubZone::query()->create(['name' => 'Зал']);
    // A diskless club: every PC has the Windows name of the image; the club map name is what staff know.
    ClubComputer::query()->create(['club_zone_id' => $zone->id, 'number' => 1, 'display_name' => 'PC-01', 'device_name' => 'PC191', 'status' => 'online', 'last_seen_at' => now()->subMinutes(10)]);
    ClubComputer::query()->create(['club_zone_id' => $zone->id, 'number' => 2, 'device_name' => 'PC-02', 'status' => 'offline', 'last_seen_at' => now()->subMinutes(10)]);
    ClubComputer::query()->create(['club_zone_id' => $zone->id, 'number' => 3, 'device_name' => 'PC-03', 'status' => 'online', 'last_seen_at' => now()]);

    ClubSubscription::query()->where('club_id', app(CurrentClub::class)->id())->update(['status' => 'trialing', 'trial_ends_at' => now()->addDays(2)]);
    ClubInvitation::query()->create(['role' => 'operator', 'token_hash' => hash('sha256', 'x'), 'expires_at' => now()->addHours(5)]);

    $response = $this->actingAs($owner)->getJson(route('notifications.index'))->assertSuccessful();
    $titles = collect($response->json('items'))->pluck('title');

    expect(collect($response->json('items'))->firstWhere('title', 'Неудачный вход')['body'])->toBe('Неудачный вход: Кассир Айгерим');
    expect($titles->all())->toEqualCanonicalizing([
        'Неудачный вход',
        'Низкий остаток: 1 товар',
        'Не на связи: 1 ПК',
        'Пробный период заканчивается',
        'Приглашение скоро истечёт',
    ])
        ->and($response->json('unread_count'))->toBe(5)
        ->and(collect($response->json('items'))->firstWhere('title', 'Не на связи: 1 ПК')['body'])->toBe('PC-01 (Зал)')
        ->and(collect($response->json('items'))->firstWhere('title', 'Низкий остаток: 1 товар')['body'])->toBe('Red Bull (2 шт.)');
});

test('opening the panel marks notifications as seen until something new happens', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    logEvent($other, 'computer.controlled', 'Управление ПК (restart): PC-01');

    $this->actingAs($owner)->getJson(route('notifications.index'))->assertJsonPath('unread_count', 1);
    $this->postJson(route('notifications.seen'))->assertSuccessful();
    $this->getJson(route('notifications.index'))
        ->assertJsonPath('unread_count', 0)
        ->assertJsonPath('items.0.unread', false);

    $this->travel(1)->minutes();
    logEvent($other, 'settings.updated', 'Изменены настройки: currency');

    $this->getJson(route('notifications.index'))
        ->assertJsonPath('unread_count', 1)
        ->assertJsonPath('items.0.title', 'Изменение настроек')
        ->assertJsonPath('items.0.unread', true);
});

test('notifications are filtered by role', function () {
    $other = User::factory()->create();
    logEvent($other, 'auth.login_failed', 'Неудачный вход');
    Product::factory()->create(['quantity' => 1]);
    $zone = ClubZone::query()->create(['name' => 'Зал']);
    ClubComputer::query()->create(['club_zone_id' => $zone->id, 'number' => 1, 'status' => 'online', 'last_seen_at' => now()->subHour()]);

    $cashier = memberWithRole('cashier');
    $this->actingAs($cashier)->getJson(route('notifications.index'))
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('items.0.id', 'low-stock');

    $operator = memberWithRole('operator');
    $this->actingAs($operator)->getJson(route('notifications.index'))
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('items.0.id', 'computers-offline');
});

test('the bell renders without a permanent unread dot', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee('id="notifications-button"', false)
        ->assertSee('data-notifications-dot hidden', false)
        ->assertSee(route('notifications.index'), false);
});

test('the notification panel has a sound switch and checks for new notifications', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)->get(route('dashboard'))->assertOk()
        ->assertSee('data-notifications-sound', false)
        ->assertSee(__('Звук уведомлений включён'))
        ->assertSee('setInterval(', false);
});
