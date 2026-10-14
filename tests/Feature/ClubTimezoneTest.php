<?php

use App\Models\ActivityLog;
use App\Models\CashShift;
use App\Models\CashTransaction;
use App\Models\Club;
use App\Models\ClubMembership;
use App\Models\User;
use App\Services\CurrentClub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function clubIn(string $timezone): Club
{
    $club = Club::query()->findOrFail(app(CurrentClub::class)->id());
    $club->forceFill(['timezone' => $timezone])->save();
    app(CurrentClub::class)->set($club->refresh());

    return $club;
}

function cashAt(string $utc, float $amount, User $user): CashTransaction
{
    Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
    $shift = CashShift::query()->firstOrCreate(['status' => CashShift::STATUS_OPEN], ['opened_by' => $user->id, 'open_marker' => true, 'opening_balance' => 0, 'opened_at' => now()]);

    return $shift->transactions()->create(['user_id' => $user->id, 'type' => CashTransaction::TYPE_INCOME, 'amount' => $amount, 'description' => 'test']);
}

test('times are stored in UTC and shown in the club time zone', function () {
    $user = User::factory()->create();
    clubIn('Asia/Almaty');
    $transaction = cashAt('2026-10-14 10:00:00', 100, $user);

    expect(DB::table('cash_transactions')->where('id', $transaction->id)->value('created_at'))->toBe('2026-10-14 10:00:00')
        ->and($transaction->refresh()->created_at->format('H:i P'))->toBe('15:00 +05:00');

    clubIn('Europe/Moscow');
    expect(CashTransaction::query()->findOrFail($transaction->id)->created_at->format('H:i P'))->toBe('13:00 +03:00');

    $transaction->update(['description' => 'changed']); // saving a model read in local time keeps UTC
    expect(DB::table('cash_transactions')->where('id', $transaction->id)->value('created_at'))->toBe('2026-10-14 10:00:00');
});

test('today on the dashboard is the local day of the club', function () {
    $user = User::factory()->create();
    clubIn('Asia/Almaty');
    cashAt('2026-10-14 18:30:00', 100, $user); // 23:30 local, 14 October
    cashAt('2026-10-14 19:30:00', 250, $user); // 00:30 local, 15 October

    Carbon::setTestNow(Carbon::parse('2026-10-14 20:30:00', 'UTC')); // 01:30 local, 15 October
    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertViewHas('todayIncome', 250.0)
        ->assertViewHas('todayTransactionCount', 1)
        ->assertViewHas('chartLabels', fn (array $labels) => end($labels) === '15.10')
        ->assertViewHas('chartIncome', fn (array $income) => array_slice($income, -2) === [100.0, 250.0]);
});

test('the activity log date filter uses local days', function () {
    $user = User::factory()->create();
    clubIn('Asia/Almaty');
    Carbon::setTestNow(Carbon::parse('2026-10-14 19:30:00', 'UTC')); // 15 October locally
    $this->actingAs($user)->post(route('auth.logout'));
    Carbon::setTestNow(Carbon::parse('2026-10-14 20:00:00', 'UTC'));

    $this->actingAs($user)->get(route('activity-logs.index', ['from' => '2026-10-15', 'to' => '2026-10-15']))
        ->assertOk()
        ->assertSee(__('Выход из системы: :user', ['user' => $user->name]));
    $this->actingAs($user)->get(route('activity-logs.index', ['from' => '2026-10-14', 'to' => '2026-10-14']))
        ->assertDontSee(__('Выход из системы: :user', ['user' => $user->name]));
});

test('a manager sets the club time zone and the change is logged', function () {
    $manager = User::factory()->create();
    ClubMembership::query()->where('user_id', $manager->id)->update(['role' => 'manager']);

    $this->actingAs($manager)->get(route('settings.club'))->assertOk()->assertSee('Asia/Almaty')->assertSee('(UTC+05:00)');
    $this->actingAs($manager)->post(route('settings.timezone'), ['timezone' => 'Asia/Tashkent'])->assertRedirect();
    $this->actingAs($manager)->post(route('settings.timezone'), ['timezone' => 'Mars/Olympus'])->assertSessionHasErrors('timezone');

    $club = Club::query()->findOrFail(app(CurrentClub::class)->id());
    expect($club->timezone)->toBe('Asia/Tashkent')
        ->and(ActivityLog::query()->where('event', 'club.timezone_changed')->sole()->details)->toBe(['from' => 'Asia/Almaty', 'to' => 'Asia/Tashkent']);
});

test('a cashier cannot change the time zone', function () {
    $cashier = User::factory()->create();
    ClubMembership::query()->where('user_id', $cashier->id)->update(['role' => 'cashier']);

    $this->actingAs($cashier)->post(route('settings.timezone'), ['timezone' => 'UTC'])->assertForbidden();
});

test('the top bar clock shows club time', function () {
    $user = User::factory()->create();
    clubIn('Asia/Almaty');
    Carbon::setTestNow(Carbon::parse('2026-10-14 07:08:09', 'UTC'));

    $this->actingAs($user)->get(route('dashboard'))
        ->assertSee('data-timezone="Asia/Almaty"', false)
        ->assertSee('data-now="'.now()->getTimestampMs().'"', false)
        ->assertSee('12:08', false);
});

test('API responses keep UTC timestamps', function () {
    [$club, $owner] = integrationClub();
    $club->forceFill(['timezone' => 'Asia/Almaty'])->save();
    [, $plain] = issueApiKey($club, $owner, ['sales:read']);
    app(CurrentClub::class)->set($club);
    cashAt('2026-10-14 10:00:00', 50, $owner)->update(['receipt_data' => ['items' => []]]);

    $this->getJson('/api/v1/sales', ['Authorization' => "Bearer {$plain}"])
        ->assertOk()
        ->assertJsonPath('data.0.created_at', '2026-10-14T10:00:00+00:00');
});
