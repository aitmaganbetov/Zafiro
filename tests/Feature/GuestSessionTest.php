<?php

use App\Models\CashShift;
use App\Models\CashTransaction;
use App\Models\ClubComputer;
use App\Models\ClubMembership;
use App\Models\ClubZone;
use App\Models\ComputerReservation;
use App\Models\Tariff;
use App\Models\User;
use App\Services\ClubComputerSessions;
use App\Services\CurrentClub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

function guestCashier(): User
{
    $user = User::factory()->create();
    ClubMembership::query()->where('user_id', $user->id)->update(['role' => 'cashier']);

    return $user;
}

function openShift(User $by): void
{
    CashShift::query()->forceCreate(['opened_by' => $by->id, 'status' => CashShift::STATUS_OPEN, 'open_marker' => true, 'opening_balance' => 0, 'opened_at' => now()]);
}

test('a cashier starts a tariff on PCs for a guest without an account, paid into the open shift', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-16 10:00:00', 'UTC'));
    $cashier = guestCashier();
    $hall = ClubZone::query()->create(['name' => 'Hall']);
    [$first, $second] = [ClubComputer::query()->create(['club_zone_id' => $hall->id, 'number' => 1]), ClubComputer::query()->create(['club_zone_id' => $hall->id, 'number' => 2])];
    $tariff = Tariff::query()->create(['name' => 'Час', 'duration_minutes' => 60, 'price' => 700, 'currency' => 'KZT', 'payment_method' => 'main_or_bonus', 'is_active' => true]);

    $this->actingAs($cashier)->get(route('club-map.index'))->assertOk()->assertSee('Подключить тариф');
    $this->postJson(route('club-computers.guest-session'), ['computer_ids' => [$first->id], 'tariff_id' => $tariff->id, 'payment_method' => 'cash'])
        ->assertJsonValidationErrors('payment_method');

    openShift($cashier);
    $this->postJson(route('club-computers.guest-session'), ['computer_ids' => [$first->id, $second->id], 'tariff_id' => $tariff->id, 'payment_method' => 'card'])
        ->assertOk()->assertJsonPath('amount', '1400.00');

    foreach ([$first, $second] as $computer) {
        expect($computer->fresh())
            ->session_guest->toBeTrue()
            ->current_session_user_id->toBeNull()
            ->and($computer->fresh()->hasPaidSession())->toBeTrue()
            ->and($computer->fresh()->session_ends_at->equalTo(now()->addHour()))->toBeTrue();
    }
    expect(CashTransaction::query()->sole())->type->toBe('income')->amount->toBe('1400.00');

    // More time on a guest PC extends it.
    $this->postJson(route('club-computers.guest-session'), ['computer_ids' => [$first->id], 'tariff_id' => $tariff->id, 'payment_method' => 'cash'])->assertOk();
    expect($first->fresh()->session_ends_at->equalTo(now()->addHours(2)))->toBeTrue();
});

test('minutes are priced by the zone; busy PCs, bookings and zones without a price are refused', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-16 10:00:00', 'UTC'));
    $cashier = guestCashier();
    openShift($cashier);
    $priced = ClubZone::query()->create(['name' => 'VIP', 'price_per_hour' => 600]);
    $plain = ClubZone::query()->create(['name' => 'Plain']);
    $pc = ClubComputer::query()->create(['club_zone_id' => $priced->id, 'number' => 1]);
    $plainPc = ClubComputer::query()->create(['club_zone_id' => $plain->id, 'number' => 2]);
    $busy = ClubComputer::query()->create(['club_zone_id' => $priced->id, 'number' => 3]);
    $busy->update(['current_session_id' => 's', 'current_session_user_id' => User::factory()->create()->id, 'session_ends_at' => now()->addHour()]);
    $booked = ClubComputer::query()->create(['club_zone_id' => $priced->id, 'number' => 4]);
    ComputerReservation::query()->create(['club_computer_id' => $booked->id, 'customer_name' => 'X', 'starts_at' => now()->addMinutes(20), 'ends_at' => now()->addHours(2)]);

    $this->actingAs($cashier);
    $this->postJson(route('club-computers.guest-session'), ['computer_ids' => [$pc->id], 'minutes' => 30, 'payment_method' => 'qr'])->assertOk()->assertJsonPath('amount', '300.00');
    $this->postJson(route('club-computers.guest-session'), ['computer_ids' => [$plainPc->id], 'minutes' => 30, 'payment_method' => 'qr'])->assertJsonValidationErrors('minutes');
    $this->postJson(route('club-computers.guest-session'), ['computer_ids' => [$busy->id], 'minutes' => 30, 'payment_method' => 'qr'])->assertJsonValidationErrors('computer_ids');
    $this->postJson(route('club-computers.guest-session'), ['computer_ids' => [$booked->id], 'minutes' => 30, 'payment_method' => 'qr'])->assertJsonValidationErrors('computer_ids');
    expect(CashTransaction::query()->count())->toBe(1);
});

test('the agent sees the guest session in the heartbeat; a customer cannot buy on it; operators cannot sell it', function () {
    $cashier = guestCashier();
    openShift($cashier);
    $pc = ClubComputer::query()->create(['club_zone_id' => ClubZone::query()->create(['name' => 'VIP', 'price_per_hour' => 600])->id, 'number' => 1]);
    $token = app(ClubComputerSessions::class)->issueAgentToken($pc);
    $this->actingAs($cashier)->postJson(route('club-computers.guest-session'), ['computer_ids' => [$pc->id], 'minutes' => 30, 'payment_method' => 'cash'])->assertOk();

    app('auth')->forgetGuards();
    app(CurrentClub::class)->clear();
    $this->postJson(route('api.agent.heartbeat'), [], ['X-Agent-Token' => $token])->assertOk()
        ->assertJsonPath('session.guest', true)
        ->assertJsonPath('session.user_id', null);
    // An agent heartbeat that reports idle does not wipe the paid guest time.
    $this->postJson(route('api.agent.heartbeat'), ['state' => 'idle'], ['X-Agent-Token' => $token])->assertOk()->assertJsonPath('session.guest', true);

    $operator = User::factory()->create();
    ClubMembership::query()->withoutGlobalScope('club')->where('user_id', $operator->id)->update(['role' => 'operator']);
    $this->actingAs($operator)->postJson(route('club-computers.guest-session'), ['computer_ids' => [$pc->id], 'minutes' => 30, 'payment_method' => 'cash'])->assertForbidden();
});
