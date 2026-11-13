<?php

use App\Models\ActivityLog;
use App\Models\Club;
use App\Models\ClubComputer;
use App\Models\ClubZone;
use App\Models\ComputerReservation;
use App\Models\MemberBalance;
use App\Models\SessionPurchase;
use App\Models\Tariff;
use App\Models\User;
use App\Services\ClubComputerSessions;
use App\Services\CurrentClub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

/** The test club in UTC with booking from the app switched on (unless a test says otherwise). */
function bookingClub(array $club = []): Club
{
    $model = Club::query()->findOrFail(app(CurrentClub::class)->id());
    $model->forceFill(['timezone' => 'UTC', 'settings' => ['club' => ['booking_interval' => 10, 'app_booking_enabled' => true, ...$club]]])->save();
    app(CurrentClub::class)->set($model);

    return $model;
}

function bookingComputer(int $number = 1, ?string $price = '600.00'): ClubComputer
{
    $zone = ClubZone::query()->firstOrCreate(['name' => 'VIP'], ['price_per_hour' => $price]);

    return ClubComputer::query()->create(['club_zone_id' => $zone->id, 'number' => $number]);
}

/** A guest of the club with money on the balance, and their app token. */
function bookingGuest(string $balance = '1000.00', string $bonus = '0.00'): array
{
    $guest = User::factory()->create();
    MemberBalance::query()->where('user_id', $guest->id)->update(['balance' => $balance, 'bonus_balance' => $bonus]);

    return [$guest, $guest->createToken('mobile', ['club:'.app(CurrentClub::class)->id()])->plainTextToken];
}

/** The next request starts without the club and the user of the previous one. */
function freshRequest(): void
{
    app(CurrentClub::class)->clear();
    app('auth')->forgetGuards();
}

test('booking from the app is off until the club switches it on', function () {
    bookingClub(['app_booking_enabled' => false]);
    $computer = bookingComputer();
    [, $token] = bookingGuest();

    freshRequest();
    $this->withToken($token)->getJson(route('api.guest.bookings.index'))->assertOk()->assertJsonPath('enabled', false);
    freshRequest();
    $this->withToken($token)->postJson(route('api.guest.bookings.store'), ['club_computer_id' => $computer->id, 'starts_at' => now()->addHour()->toIso8601String(), 'duration_minutes' => 60])
        ->assertForbidden();
    expect(ComputerReservation::query()->count())->toBe(0);
});

test('a guest books a free PC and pays the zone price from the balance', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-15 10:00:00', 'UTC'));
    bookingClub();
    $computer = bookingComputer();
    $other = bookingComputer(2);
    [$guest, $token] = bookingGuest('1000.00');

    freshRequest();
    $this->withToken($token)->postJson(route('api.guest.bookings.store'), ['club_computer_id' => $computer->id, 'starts_at' => '2026-11-15T17:00:00+05:00', 'duration_minutes' => 90])
        ->assertCreated()
        ->assertJsonPath('booking.computer', 'PC-01')
        ->assertJsonPath('booking.zone', 'VIP')
        ->assertJsonPath('booking.starts_at', '2026-11-15T12:00:00+00:00')
        ->assertJsonPath('booking.paid', '900.00')
        ->assertJsonPath('booking.state', 'pending');

    $reservation = ComputerReservation::query()->sole();
    expect($reservation->from_app)->toBeTrue()
        ->and($reservation->paid_main_cents)->toBe(90000)
        ->and($reservation->getRawOriginal('ends_at'))->toBe('2026-11-15 13:30:00')
        ->and((string) MemberBalance::query()->where('user_id', $guest->id)->value('balance'))->toBe('100.00')
        ->and(ActivityLog::query()->where('event', 'reservation.paid')->where('user_id', $guest->id)->exists())->toBeTrue();

    // The booked PC is not free for an overlapping period (with the club's interval), the other one is.
    freshRequest();
    $zones = $this->withToken($token)->getJson(route('api.guest.bookings.options', ['starts_at' => '2026-11-15T13:35:00+00:00', 'duration_minutes' => 60]))
        ->assertOk()->json('zones');
    expect($zones[0]['price'])->toBe('600.00')
        ->and(collect($zones[0]['computers'])->pluck('available', 'id')->all())->toBe([$computer->id => false, $other->id => true]);

    // Not enough money for one more: nothing is booked or charged.
    freshRequest();
    $this->withToken($token)->postJson(route('api.guest.bookings.store'), ['club_computer_id' => $other->id, 'starts_at' => '2026-11-15T15:00:00+00:00', 'duration_minutes' => 60])
        ->assertUnprocessable()->assertJsonValidationErrors('balance');
    expect(ComputerReservation::query()->count())->toBe(1);
});

test('a guest has at most two bookings ahead and cannot book in the past or too far', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-15 10:00:00', 'UTC'));
    bookingClub();
    $computer = bookingComputer();
    [, $token] = bookingGuest('10000.00');
    $book = function (string $start) use ($token, $computer) {
        freshRequest();

        return $this->withToken($token)->postJson(route('api.guest.bookings.store'), ['club_computer_id' => $computer->id, 'starts_at' => $start, 'duration_minutes' => 60]);
    };

    $book('2026-11-15T09:00:00+00:00')->assertUnprocessable()->assertJsonValidationErrors('starts_at');
    $book('2026-11-30T09:00:00+00:00')->assertUnprocessable()->assertJsonValidationErrors('starts_at');
    $book('2026-11-15T12:00:00+00:00')->assertCreated();
    $book('2026-11-15T14:00:00+00:00')->assertCreated();
    $book('2026-11-15T16:00:00+00:00')->assertUnprocessable()->assertJsonValidationErrors('starts_at');
});

test('the guest cancels a booking before it starts and gets the money back, when the club allows it', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-15 10:00:00', 'UTC'));
    bookingClub();
    $computer = bookingComputer();
    [$guest, $token] = bookingGuest('1000.00');

    freshRequest();
    $id = $this->withToken($token)->postJson(route('api.guest.bookings.store'), ['club_computer_id' => $computer->id, 'starts_at' => '2026-11-15T12:00:00+00:00', 'duration_minutes' => 60])
        ->assertCreated()->json('booking.id');
    expect((string) MemberBalance::query()->where('user_id', $guest->id)->value('balance'))->toBe('400.00');

    freshRequest();
    $this->withToken($token)->postJson(route('api.guest.bookings.cancel', $id))->assertOk();
    expect(ComputerReservation::query()->sole()->cancelled_at)->not->toBeNull()
        ->and((string) MemberBalance::query()->where('user_id', $guest->id)->value('balance'))->toBe('1000.00');

    freshRequest();
    $history = $this->withToken($token)->getJson(route('api.guest.history'))->assertOk()->json('data');
    expect(collect($history)->pluck('kind')->take(2)->all())->toBe(['refund', 'purchase']);

    // The club turned cancelling off: the booking stays paid.
    bookingClub(['app_booking_cancel' => false]);
    freshRequest();
    $id = $this->withToken($token)->postJson(route('api.guest.bookings.store'), ['club_computer_id' => $computer->id, 'starts_at' => '2026-11-15T14:00:00+00:00', 'duration_minutes' => 60])
        ->assertCreated()->assertJsonPath('booking.can_cancel', false)->json('booking.id');
    freshRequest();
    $this->withToken($token)->postJson(route('api.guest.bookings.cancel', $id))->assertUnprocessable();
    expect((string) MemberBalance::query()->where('user_id', $guest->id)->value('balance'))->toBe('400.00');
});

test('a guest cannot cancel a booking of someone else or one that has started', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-15 10:00:00', 'UTC'));
    bookingClub();
    $computer = bookingComputer();
    [, $token] = bookingGuest();
    [, $strangerToken] = bookingGuest();

    freshRequest();
    $id = $this->withToken($token)->postJson(route('api.guest.bookings.store'), ['club_computer_id' => $computer->id, 'starts_at' => '2026-11-15T10:30:00+00:00', 'duration_minutes' => 60])
        ->assertCreated()->json('booking.id');

    freshRequest();
    $this->withToken($strangerToken)->postJson(route('api.guest.bookings.cancel', $id))->assertNotFound();

    Carbon::setTestNow(Carbon::parse('2026-11-15 10:40:00', 'UTC'));
    freshRequest();
    $this->withToken($token)->postJson(route('api.guest.bookings.cancel', $id))->assertUnprocessable();
});

test('staff cancelling a paid booking give the money back', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-15 10:00:00', 'UTC'));
    $staff = User::factory()->create();
    bookingClub(['app_booking_cancel' => false]);
    $computer = bookingComputer();
    [$guest, $token] = bookingGuest('1000.00');

    freshRequest();
    $id = $this->withToken($token)->postJson(route('api.guest.bookings.store'), ['club_computer_id' => $computer->id, 'starts_at' => '2026-11-15T12:00:00+00:00', 'duration_minutes' => 60])
        ->assertCreated()->json('booking.id');

    freshRequest();
    $this->actingAs($staff)->post(route('reservations.cancel', $id))->assertSessionHasNoErrors();
    expect((string) MemberBalance::query()->where('user_id', $guest->id)->value('balance'))->toBe('1000.00');
});

test('the paid time starts when the guest signs in on the booked PC during the booking', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-15 10:00:00', 'UTC'));
    bookingClub();
    $computer = bookingComputer();
    $agent = app(ClubComputerSessions::class)->issueAgentToken($computer);
    [$guest, $token] = bookingGuest('1000.00');
    [, $strangerToken] = bookingGuest();

    freshRequest();
    $this->withToken($token)->postJson(route('api.guest.bookings.store'), ['club_computer_id' => $computer->id, 'starts_at' => '2026-11-15T12:00:00+00:00', 'duration_minutes' => 60])
        ->assertCreated();

    // Too early (the club's interval is 10 minutes), and someone else signed in: no session.
    freshRequest();
    $this->withToken($token)->postJson(route('api.agent.heartbeat'), [], ['X-Agent-Token' => $agent])->assertOk()->assertJsonPath('session', null);
    Carbon::setTestNow(Carbon::parse('2026-11-15 11:55:00', 'UTC'));
    freshRequest();
    $this->withToken($strangerToken)->postJson(route('api.agent.heartbeat'), [], ['X-Agent-Token' => $agent])->assertOk()->assertJsonPath('session', null);

    // The guest, 5 minutes early: the booked hour starts now.
    freshRequest();
    $this->withToken($token)->postJson(route('api.agent.heartbeat'), [], ['X-Agent-Token' => $agent])->assertOk()
        ->assertJsonPath('session.user_id', $guest->id)
        ->assertJsonPath('session.ends_at', '2026-11-15T12:55:00+00:00');

    expect(ComputerReservation::query()->sole()->arrived_at)->not->toBeNull()
        ->and(SessionPurchase::query()->sole()->only(['minutes', 'main_cents']))->toBe(['minutes' => 60, 'main_cents' => 60000]);
});

test('a guest coming late gets the rest of the booking; staff marking the arrival start it too', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-15 10:00:00', 'UTC'));
    $staff = User::factory()->create();
    bookingClub();
    $computer = bookingComputer();
    [$guest, $token] = bookingGuest('1000.00');

    freshRequest();
    $id = $this->withToken($token)->postJson(route('api.guest.bookings.store'), ['club_computer_id' => $computer->id, 'starts_at' => '2026-11-15T12:00:00+00:00', 'duration_minutes' => 60])
        ->assertCreated()->json('booking.id');

    Carbon::setTestNow(Carbon::parse('2026-11-15 12:30:00', 'UTC'));
    freshRequest();
    $this->actingAs($staff)->post(route('reservations.arrive', $id))->assertSessionHasNoErrors();

    $computer->refresh();
    expect($computer->current_session_user_id)->toBe($guest->id)
        ->and($computer->session_ends_at->utc()->format('H:i'))->toBe('13:00')
        ->and(SessionPurchase::query()->sole()->only(['minutes', 'main_cents']))->toBe(['minutes' => 30, 'main_cents' => 30000]);
});

test('buying time on the PC does not close the guest\'s paid booking', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-15 10:00:00', 'UTC'));
    bookingClub();
    $computer = bookingComputer();
    [, $token] = bookingGuest('2000.00');

    freshRequest();
    $this->withToken($token)->postJson(route('api.guest.bookings.store'), ['club_computer_id' => $computer->id, 'starts_at' => '2026-11-15T11:00:00+00:00', 'duration_minutes' => 60])
        ->assertCreated();

    freshRequest();
    $this->withToken($token)->postJson(route('api.guest.minutes.purchase'), ['computer_id' => $computer->id, 'minutes' => 60])->assertOk();

    expect(ComputerReservation::query()->sole()->arrived_at)->toBeNull();
});

test('a guest books a PC as a tariff of its zone: the tariff price, its time, its purchase limit', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-16 10:00:00', 'UTC')); // Monday
    bookingClub();
    $computer = bookingComputer();
    $other = ClubComputer::query()->create(['club_zone_id' => ClubZone::query()->create(['name' => 'Общий', 'price_per_hour' => '300.00'])->id, 'number' => 2]);
    [$guest, $token] = bookingGuest('5000.00');
    $tariff = Tariff::query()->create(['name' => '3 часа', 'duration_minutes' => 180, 'price' => 1200, 'currency' => 'KZT', 'payment_method' => 'main_or_bonus', 'is_active' => true, 'quantity_limit_enabled' => true, 'quantity_limit' => 1]);
    $tariff->zones()->attach($computer->club_zone_id, ['club_id' => app(CurrentClub::class)->id()]);
    // Valid at night only: 22:00 – 08:00 every day of the week.
    $night = Tariff::query()->create(['name' => 'Ночь', 'duration_minutes' => 600, 'price' => 2000, 'currency' => 'KZT', 'payment_method' => 'main_or_bonus', 'is_active' => true,
        'validity_periods' => [['start_day' => 'mon', 'start_time' => '22:00', 'end_day' => 'tue', 'end_time' => '08:00']]]);
    $start = '2026-11-16T12:00:00+00:00';

    freshRequest();
    $tariffs = $this->withToken($token)->getJson(route('api.guest.bookings.tariffs', ['starts_at' => $start]))->assertOk()->json('tariffs');
    expect($tariffs)->toHaveCount(1)
        ->and($tariffs[0])->toMatchArray(['id' => $tariff->id, 'minutes' => 180, 'price' => '1200.00', 'zone_ids' => [$computer->club_zone_id]]);

    // At night the night tariff is offered, its time cut at 08:00.
    freshRequest();
    $nightTariffs = $this->withToken($token)->getJson(route('api.guest.bookings.tariffs', ['starts_at' => '2026-11-16T23:00:00+00:00']))->json('tariffs');
    expect(collect($nightTariffs)->firstWhere('id', $night->id)['minutes'])->toBe(540);

    // Only the tariff's zone, at the tariff price.
    freshRequest();
    $options = $this->withToken($token)->getJson(route('api.guest.bookings.options', ['starts_at' => $start, 'tariff_id' => $tariff->id]))->assertOk();
    expect($options->json('minutes'))->toBe(180)
        ->and(collect($options->json('zones'))->pluck('price', 'name')->all())->toBe(['VIP' => '1200.00']);

    freshRequest();
    $this->withToken($token)->postJson(route('api.guest.bookings.store'), ['club_computer_id' => $other->id, 'starts_at' => $start, 'tariff_id' => $tariff->id])
        ->assertUnprocessable()->assertJsonValidationErrors('club_computer_ids');
    freshRequest();
    $this->withToken($token)->postJson(route('api.guest.bookings.store'), ['club_computer_id' => $computer->id, 'starts_at' => $start, 'tariff_id' => $tariff->id])
        ->assertCreated()->assertJsonPath('booking.tariff', '3 часа')->assertJsonPath('booking.paid', '1200.00')
        ->assertJsonPath('booking.ends_at', '2026-11-16T15:00:00+00:00');
    expect(ComputerReservation::query()->sole()->tariff_id)->toBe($tariff->id)
        ->and((string) MemberBalance::query()->where('user_id', $guest->id)->value('balance'))->toBe('3800.00');

    // The limit of one purchase is used up by the booking.
    freshRequest();
    $this->withToken($token)->postJson(route('api.guest.bookings.store'), ['club_computer_id' => $computer->id, 'starts_at' => '2026-11-16T16:00:00+00:00', 'tariff_id' => $tariff->id])
        ->assertUnprocessable()->assertJsonValidationErrors('tariff_id');

    // On the PC the time starts as that tariff.
    $agent = app(ClubComputerSessions::class)->issueAgentToken($computer);
    Carbon::setTestNow(Carbon::parse('2026-11-16 12:00:00', 'UTC'));
    freshRequest();
    $this->withToken($token)->postJson(route('api.agent.heartbeat'), [], ['X-Agent-Token' => $agent])->assertOk()
        ->assertJsonPath('session.ends_at', '2026-11-16T15:00:00+00:00');
    expect(SessionPurchase::query()->sole()->only(['kind', 'tariff_id', 'minutes']))->toBe(['kind' => 'tariff', 'tariff_id' => $tariff->id, 'minutes' => 180]);
});

test('the guest picks a PC first: the club PCs by zone, then only the tariffs of its zone', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-16 10:00:00', 'UTC'));
    bookingClub();
    $vip = bookingComputer();
    $common = ClubComputer::query()->create(['club_zone_id' => ClubZone::query()->create(['name' => 'Общий'])->id, 'number' => 2]);
    ClubComputer::query()->create(['club_zone_id' => $common->club_zone_id, 'number' => 3, 'is_reserved' => true]);
    [, $token] = bookingGuest();
    $vipOnly = Tariff::query()->create(['name' => 'VIP 3 часа', 'duration_minutes' => 180, 'price' => 1200, 'currency' => 'KZT', 'payment_method' => 'main_or_bonus', 'is_active' => true]);
    $vipOnly->zones()->attach($vip->club_zone_id, ['club_id' => app(CurrentClub::class)->id()]);
    $everywhere = Tariff::query()->create(['name' => 'Час', 'duration_minutes' => 60, 'price' => 300, 'currency' => 'KZT', 'payment_method' => 'main_or_bonus', 'is_active' => true]);

    freshRequest();
    $zones = $this->withToken($token)->getJson(route('api.guest.bookings.computers'))->assertOk()->json('zones');
    expect(collect($zones)->pluck('name')->all())->toBe(['VIP', 'Общий'])
        ->and($zones[0]['price_per_hour'])->toBe('600.00')
        ->and($zones[1]['price_per_hour'])->toBeNull()
        ->and($zones[1]['computers'])->toBe([['id' => $common->id, 'name' => 'PC-02']]);

    $tariffsOf = function (ClubComputer $computer) use ($token) {
        freshRequest();

        return collect($this->withToken($token)->getJson(route('api.guest.bookings.tariffs', ['starts_at' => '2026-11-16T12:00:00+00:00', 'computer_id' => $computer->id]))
            ->assertOk()->json('tariffs'))->pluck('id')->all();
    };
    expect($tariffsOf($common))->toBe([$everywhere->id])
        ->and($tariffsOf($vip))->toBe([$everywhere->id, $vipOnly->id]);
});

test('the journal and the agent show booking times in the club time zone', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-16 03:00:00', 'UTC'));
    bookingClub();
    Club::query()->whereKey(app(CurrentClub::class)->id())->update(['timezone' => 'Asia/Almaty']); // UTC+5
    $computer = bookingComputer();
    [, $token] = bookingGuest();
    [, $strangerToken] = bookingGuest('5000.00');

    freshRequest();
    $this->withToken($token)->postJson(route('api.guest.bookings.store'), ['club_computer_id' => $computer->id, 'starts_at' => '2026-11-16T05:30:00+00:00', 'duration_minutes' => 60])
        ->assertCreated();
    expect(ActivityLog::query()->where('event', 'reservation.paid')->sole()->details['starts_at'])->toBe('16.11.2026 10:30');

    freshRequest();
    $this->withToken($strangerToken)->postJson(route('api.guest.minutes.purchase'), ['computer_id' => $computer->id, 'minutes' => 150])
        ->assertUnprocessable()->assertJsonPath('errors.computer_id.0', fn (string $message) => str_contains($message, '10:30'));
});

test('a guest books several PCs together: one payment, one code, cancelled together', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-17 10:00:00', 'UTC'));
    bookingClub();
    $first = bookingComputer();
    $second = bookingComputer(2);
    [$guest, $token] = bookingGuest('2000.00');

    freshRequest();
    $booking = $this->withToken($token)->postJson(route('api.guest.bookings.store'), ['club_computer_ids' => [$first->id, $second->id], 'starts_at' => '2026-11-17T12:00:00+00:00', 'duration_minutes' => 60])
        ->assertCreated()
        ->assertJsonPath('booking.computers', ['PC-01', 'PC-02'])
        ->assertJsonPath('booking.paid', '1200.00')
        ->json('booking');
    expect($booking['code'])->toMatch('/^\d{6}$/')
        ->and(ComputerReservation::query()->pluck('code')->unique()->all())->toBe([$booking['code']])
        ->and((string) MemberBalance::query()->where('user_id', $guest->id)->value('balance'))->toBe('800.00');

    // The app lists them as one booking, and it counts once towards the limit of bookings ahead.
    freshRequest();
    expect($this->withToken($token)->getJson(route('api.guest.bookings.index'))->json('bookings'))->toHaveCount(1);

    freshRequest();
    $this->withToken($token)->postJson(route('api.guest.bookings.cancel', $booking['id']))->assertOk();
    expect(ComputerReservation::query()->whereNull('cancelled_at')->count())->toBe(0)
        ->and((string) MemberBalance::query()->where('user_id', $guest->id)->value('balance'))->toBe('2000.00');
});

test('the booking code opens only the booked PCs, from the interval before the booking, without an account', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-17 10:00:00', 'UTC'));
    bookingClub();
    $first = bookingComputer();
    $second = bookingComputer(2);
    $other = bookingComputer(3);
    $agents = collect([$first, $second, $other])->mapWithKeys(fn (ClubComputer $pc) => [$pc->id => app(ClubComputerSessions::class)->issueAgentToken($pc)]);
    [, $token] = bookingGuest('2000.00');

    freshRequest();
    $code = $this->withToken($token)->postJson(route('api.guest.bookings.store'), ['club_computer_ids' => [$first->id, $second->id], 'starts_at' => '2026-11-17T12:00:00+00:00', 'duration_minutes' => 60])
        ->assertCreated()->json('booking.code');
    $enter = function (ClubComputer $pc, string $code) use ($agents) {
        freshRequest();

        return $this->postJson(route('api.agent.booking-code'), ['code' => $code], ['X-Agent-Token' => $agents[$pc->id]]);
    };

    // Too early (the club's interval is 10 minutes).
    $enter($first, $code)->assertUnprocessable()->assertJsonValidationErrors('code');

    Carbon::setTestNow(Carbon::parse('2026-11-17 11:55:00', 'UTC'));
    $enter($other, $code)->assertUnprocessable()->assertJsonPath('errors.code.0', fn (string $message) => str_contains($message, 'не для этого ПК'));
    $enter($first, $code === '000000' ? '111111' : '000000')->assertUnprocessable();

    $enter($first, $code)->assertOk()
        ->assertJsonPath('session.guest', true)
        ->assertJsonPath('session.ends_at', '2026-11-17T12:55:00+00:00');
    $enter($second, $code)->assertOk()->assertJsonPath('session.guest', true);

    // Used: the code does not open the PC again.
    $enter($first, $code)->assertUnprocessable();
    expect(ComputerReservation::query()->whereNull('arrived_at')->count())->toBe(0)
        ->and($other->fresh()->current_session_id)->toBeNull();
});
