<?php

use App\Models\CashShift;
use App\Models\CashTransaction;
use App\Models\ClubComputer;
use App\Models\ClubMembership;
use App\Models\ClubZone;
use App\Models\ComputerReservation;
use App\Models\SessionPurchase;
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

test('the start tariff dialog knows the zones of each tariff, so it shows the tariffs of the selected PCs', function () {
    $cashier = guestCashier();
    $hall = ClubZone::query()->create(['name' => 'Hall']);
    $vip = ClubZone::query()->create(['name' => 'VIP']);
    $hallOnly = Tariff::query()->create(['name' => 'Зальный', 'duration_minutes' => 60, 'price' => 700, 'currency' => 'KZT', 'payment_method' => 'main_or_bonus', 'is_active' => true]);
    $hallOnly->zones()->syncWithPivotValues([$hall->id], ['club_id' => $hall->club_id]);
    Tariff::query()->create(['name' => 'Везде', 'duration_minutes' => 60, 'price' => 500, 'currency' => 'KZT', 'payment_method' => 'main_or_bonus', 'is_active' => true]);
    ClubComputer::query()->create(['club_zone_id' => $vip->id, 'number' => 3]);

    $this->actingAs($cashier)->get(route('club-map.index'))->assertOk()
        ->assertSee('data-tariff-zones="'.$hall->id.'"><input type="radio" name="choice" value="tariff:'.$hallOnly->id.'"', false)
        ->assertSee('data-tariff-zones=""><input type="radio"', false);

    // The server keeps the rule too: a tariff of another zone is not started.
    openShift($cashier);
    $this->postJson(route('club-computers.guest-session'), ['computer_ids' => [ClubComputer::query()->value('id')], 'tariff_id' => $hallOnly->id, 'payment_method' => 'cash'])
        ->assertUnprocessable()->assertJsonValidationErrors('tariff_id');
});

test('the club map shows who sits at a PC and what they bought', function () {
    $cashier = guestCashier();
    $customer = User::factory()->create(['name' => 'Арман', 'phone' => '+77010000001']);
    $hall = ClubZone::query()->create(['name' => 'Hall']);
    $pc = ClubComputer::query()->create(['club_zone_id' => $hall->id, 'number' => 1]);
    $guestPc = ClubComputer::query()->create(['club_zone_id' => $hall->id, 'number' => 2]);
    $pc->forceFill(['current_session_id' => 'session-1', 'current_session_user_id' => $customer->id, 'session_guest' => false, 'session_ends_at' => now()->addHour()])->save();
    $guestPc->forceFill(['current_session_id' => 'session-2', 'session_guest' => true, 'session_ends_at' => now()->addHour()])->save();
    SessionPurchase::query()->create(['session_id' => 'session-1', 'user_id' => $customer->id, 'kind' => 'tariff', 'name' => 'Ночной', 'minutes' => 300, 'main_cents' => 100000, 'bonus_cents' => 0]);
    SessionPurchase::query()->create(['session_id' => 'session-1', 'user_id' => $customer->id, 'kind' => 'minutes', 'name' => 'Hall', 'minutes' => 30, 'main_cents' => 30000, 'bonus_cents' => 0]);

    $this->actingAs($cashier)->get(route('club-map.index'))->assertOk()
        ->assertSee('data-session-user="Арман · +77010000001" data-session-tariff="Ночной, Поминутно: 30 мин."', false)
        ->assertSee('data-session-user="Гость без аккаунта" data-session-tariff="Оплачено на кассе"', false)
        ->assertSee('id="computer-info-session-user"', false);
});

test('a PC on the club map glows by who uses it', function () {
    $cashier = guestCashier();
    $hall = ClubZone::query()->create(['name' => 'Hall']);
    $make = fn (int $number, array $state) => tap(ClubComputer::query()->create(['club_zone_id' => $hall->id, 'number' => $number]))->forceFill($state + ['status' => 'available', 'last_seen_at' => now()])->save();
    $make(1, []);
    $make(2, ['current_session_id' => 's-2', 'current_session_user_id' => $cashier->id, 'session_guest' => false, 'session_ends_at' => now()->addHour()]);
    $make(3, ['current_session_id' => 's-3', 'session_guest' => true, 'session_ends_at' => now()->addHour()]);
    $make(4, ['staff_until' => now()->addHour(), 'staff_kind' => 'pin']);
    $make(5, ['last_seen_at' => now()->subHour()]);

    $page = $this->actingAs($cashier)->get(route('club-map.index'))->assertOk();
    foreach ([1 => 'online pc-free', 2 => 'online pc-account', 3 => 'online pc-guest', 4 => 'online pc-staff', 5 => 'offline'] as $number => $classes) {
        $page->assertSee('<span class="cm-computer '.$classes.'" data-id="'.ClubComputer::query()->where('number', $number)->value('id').'"', false);
    }
    $page->assertSee('Гостевая сессия');
});

test('the open club map learns that a PC went off', function () {
    $cashier = guestCashier();
    $hall = ClubZone::query()->create(['name' => 'Hall']);
    $pc = ClubComputer::query()->create(['club_zone_id' => $hall->id, 'number' => 1]);
    $pc->forceFill(['status' => 'online', 'last_seen_at' => now()])->save();

    $this->actingAs($cashier)->get(route('club-map.index'))->assertOk()
        ->assertSee('<span class="cm-computer online pc-free" data-id="'.$pc->id.'"', false)
        ->assertSee(json_encode(route('club-map.state')), false);
    $this->getJson(route('club-map.state'))->assertOk()->assertJsonPath("computers.{$pc->id}.classes", 'online pc-free');

    // No heartbeat for longer than the timeout: off, no glow.
    $this->travel(ClubComputer::HEARTBEAT_TIMEOUT_SECONDS + 5)->seconds();
    $this->getJson(route('club-map.state'))->assertOk()
        ->assertJsonPath("computers.{$pc->id}.classes", 'offline')
        ->assertJsonPath("computers.{$pc->id}.connection", 'offline');
});
