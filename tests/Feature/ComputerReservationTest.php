<?php

use App\Models\Club;
use App\Models\ClubComputer;
use App\Models\ClubMembership;
use App\Models\ClubZone;
use App\Models\ComputerReservation;
use App\Models\MemberBalance;
use App\Models\Tariff;
use App\Models\User;
use App\Services\CurrentClub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

/** The test club, in UTC unless a test picks another time zone. */
function reservationClub(array $attributes = []): Club
{
    $club = Club::query()->findOrFail(app(CurrentClub::class)->id());
    $club->forceFill(['timezone' => 'UTC', ...$attributes])->save();
    app(CurrentClub::class)->set($club);

    return $club;
}

/** A PC of another club. */
function foreignComputer(): ClubComputer
{
    $club = Club::query()->create(['name' => 'Other', 'slug' => 'other']);
    $zone = ClubZone::query()->withoutGlobalScope('club')->forceCreate(['club_id' => $club->id, 'name' => 'Other hall']);

    return ClubComputer::query()->withoutGlobalScope('club')->forceCreate(['club_id' => $club->id, 'club_zone_id' => $zone->id, 'number' => 9]);
}

function reservationComputer(int $number = 1): ClubComputer
{
    $zone = ClubZone::query()->firstOrCreate(['name' => 'Hall']);

    return ClubComputer::query()->create(['club_zone_id' => $zone->id, 'number' => $number]);
}

/** Starts at the given club-local time today, as the form sends it. */
function reservationForm(ClubComputer $computer, string $start, int $minutes = 60, array $extra = []): array
{
    // The signed-in staff member is a club user too: a valid customer unless a test picks one.
    return ['club_computer_id' => $computer->id, 'starts_at' => $start, 'duration_minutes' => $minutes, 'user_id' => auth()->id(), ...$extra];
}

function setBookingInterval(int $minutes): void
{
    reservationClub(['settings' => ['club' => ['booking_interval' => $minutes]]]);
}

test('the workspace menu links to the bookings page', function () {
    $this->actingAs(User::factory()->create())->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('reservations.index'), false)
        ->assertSee('Брони');
});

test('staff book a PC for a time in the club time zone and see it on that day', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-15 06:00:00', 'UTC'));
    $owner = User::factory()->create();
    $customer = User::factory()->create(['name' => 'Арман', 'phone' => '+77011112233']);
    reservationClub(['timezone' => 'Asia/Almaty']); // UTC+5
    $computer = reservationComputer();

    $this->actingAs($owner)->post(route('reservations.store'), reservationForm($computer, '2026-10-15T18:00', 120, ['user_id' => $customer->id, 'note' => 'ДР']))
        ->assertRedirect(route('reservations.index', ['date' => '2026-10-15']))
        ->assertSessionHasNoErrors();

    $reservation = ComputerReservation::query()->sole();
    expect($reservation->getRawOriginal('starts_at'))->toBe('2026-10-15 13:00:00')
        ->and($reservation->getRawOriginal('ends_at'))->toBe('2026-10-15 15:00:00')
        ->and($reservation->user_id)->toBe($customer->id)
        ->and($reservation->customer_name)->toBe('Арман')
        ->and($reservation->customer_phone)->toBe('+77011112233')
        ->and($reservation->created_by)->toBe($owner->id);

    $this->get(route('reservations.index', ['date' => '2026-10-15']))
        ->assertOk()
        ->assertSee('18:00 – 20:00')
        ->assertSee('Арман · +77011112233')
        ->assertSee('Ожидается');
    $this->get(route('reservations.index', ['date' => '2026-10-16']))->assertOk()->assertSee('На этот день броней нет.');

    $this->get(route('activity-logs.index'))->assertSee('Бронирование ПК');
});

test('bookings of a PC keep the club interval between them; other PCs are free', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-15 06:00:00', 'UTC'));
    setBookingInterval(30);
    $this->actingAs(User::factory()->create());
    $computer = reservationComputer();
    $other = reservationComputer(2);

    $this->post(route('reservations.store'), reservationForm($computer, '2026-10-15T12:00'))->assertSessionHasNoErrors();

    // 13:00 + 30 min interval: 13:20 runs into it, 13:30 does not.
    $this->post(route('reservations.store'), reservationForm($computer, '2026-10-15T13:20'))->assertSessionHasErrors('starts_at');
    $this->post(route('reservations.store'), reservationForm($computer, '2026-10-15T10:40'))->assertSessionHasErrors('starts_at');
    $this->post(route('reservations.store'), reservationForm($computer, '2026-10-15T13:30'))->assertSessionHasNoErrors();
    $this->post(route('reservations.store'), reservationForm($other, '2026-10-15T12:00'))->assertSessionHasNoErrors();

    expect(ComputerReservation::query()->count())->toBe(3);
});

test('a booking cannot be in the past, without a club user or for a PC of another club', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-15 06:00:00', 'UTC'));
    $this->actingAs(User::factory()->create());
    reservationClub();
    $computer = reservationComputer();
    $foreign = foreignComputer();

    $this->post(route('reservations.store'), reservationForm($computer, '2026-10-15T05:00'))->assertSessionHasErrors('starts_at');
    $this->post(route('reservations.store'), reservationForm($computer, '2026-10-15T12:00', 60, ['user_id' => null]))->assertSessionHasErrors('user_id');
    $outsider = User::factory()->create();
    ClubMembership::query()->where('user_id', $outsider->id)->delete();
    $this->post(route('reservations.store'), reservationForm($computer, '2026-10-15T12:00', 60, ['user_id' => $outsider->id]))->assertSessionHasErrors('user_id');
    $this->post(route('reservations.store'), reservationForm($foreign, '2026-10-15T12:00'))->assertSessionHasErrors('club_computer_id');

    expect(ComputerReservation::query()->withoutGlobalScope('club')->count())->toBe(0);
});

test('a PC with a paid session running into the booking cannot be booked', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-15 06:00:00', 'UTC'));
    setBookingInterval(15);
    $customer = User::factory()->create();
    $this->actingAs(User::factory()->create());
    $computer = reservationComputer();
    $computer->update(['current_session_id' => 's-1', 'current_session_user_id' => $customer->id, 'session_ends_at' => now()->addHours(2)]);

    $this->post(route('reservations.store'), reservationForm($computer, '2026-10-15T08:10'))->assertSessionHasErrors('starts_at');
    $this->post(route('reservations.store'), reservationForm($computer, '2026-10-15T08:15'))->assertSessionHasNoErrors();
});

test('the customer picker finds club users by name or phone, not users of other clubs', function () {
    $this->actingAs(User::factory()->create(['name' => 'Staff']));
    User::factory()->create(['name' => 'Арман Сейтов', 'phone' => '+77011112233']);
    User::factory()->create(['name' => 'Дана', 'phone' => '+77029998877']);
    $outsider = User::factory()->create(['name' => 'Арман Чужой', 'phone' => '+77011110000']);
    ClubMembership::query()->where('user_id', $outsider->id)->delete();

    $this->getJson(route('reservations.customers', ['q' => 'Арман']))->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Арман Сейтов')
        ->assertJsonPath('data.0.phone', '+77011112233');
    $this->getJson(route('reservations.customers', ['q' => '8 702 999']))->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Дана');
    $this->getJson(route('reservations.customers', ['q' => 'А']))->assertOk()->assertJsonCount(0, 'data');
});

test('bookings can be cancelled or closed by the arrival once', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-15 06:00:00', 'UTC'));
    $this->actingAs(User::factory()->create());
    reservationClub();
    $computer = reservationComputer();
    $this->post(route('reservations.store'), reservationForm($computer, '2026-10-15T08:00'));
    $this->post(route('reservations.store'), reservationForm($computer, '2026-10-15T12:00'));
    [$first, $second] = ComputerReservation::query()->orderBy('starts_at')->get()->all();

    $this->post(route('reservations.cancel', $first))->assertSessionHasNoErrors();
    $this->post(route('reservations.arrive', $first))->assertSessionHasErrors('reservation');
    $this->post(route('reservations.arrive', $second))->assertSessionHasNoErrors();

    expect($first->fresh()->state())->toBe('cancelled')
        ->and($second->fresh()->state())->toBe('arrived');
});

test('bookings of another club are not found', function () {
    $this->actingAs(User::factory()->create());
    $foreignComputer = foreignComputer();
    $foreign = ComputerReservation::query()->withoutGlobalScope('club')->forceCreate([
        'club_id' => $foreignComputer->club_id, 'club_computer_id' => $foreignComputer->id, 'customer_name' => 'X',
        'starts_at' => now()->addHour(), 'ends_at' => now()->addHours(2),
    ]);

    $this->post(route('reservations.cancel', $foreign))->assertNotFound();
    expect($foreign->fresh()->cancelled_at)->toBeNull();
});

test('customers cannot open the bookings page', function () {
    $user = User::factory()->create();
    ClubMembership::query()->where('user_id', $user->id)->update(['role' => 'client']);

    $this->actingAs($user)->get(route('reservations.index'))->assertForbidden();
});

test('the agent does not sell time running into a booking of someone else, the booked customer may play', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-15 06:00:00', 'UTC'));
    setBookingInterval(10);
    $staff = User::factory()->create();
    $booked = User::factory()->create(['phone' => '+77012223344']);
    $stranger = User::factory()->create();
    MemberBalance::query()->whereIn('user_id', [$booked->id, $stranger->id])->update(['balance' => 1000]);
    $computer = reservationComputer();
    $tariff = Tariff::query()->create(['name' => 'Час', 'duration_minutes' => 60, 'price' => 100, 'currency' => 'KZT', 'payment_method' => 'main_or_bonus', 'is_active' => true]);

    // Booking 07:30–09:30 UTC (club time zone is UTC here) for one customer.
    $this->actingAs($staff)->post(route('reservations.store'), reservationForm($computer, '2026-10-15T07:30', 120, ['user_id' => $booked->id]))
        ->assertSessionHasNoErrors();
    expect(ComputerReservation::query()->sole()->user_id)->toBe($booked->id);

    $clubId = app(CurrentClub::class)->id();
    $purchase = function (User $user) use ($tariff, $computer, $clubId) {
        app(CurrentClub::class)->clear();
        app('auth')->forgetGuards(); // the staff member signed in above is not the customer
        $token = $user->createToken('agent', ['club:'.$clubId])->plainTextToken;

        return $this->withToken($token)->postJson(route('api.tariffs.purchase', $tariff), ['computer_id' => $computer->id]);
    };

    // 06:00 + 60 min = 07:00, plus 10 min interval, still before 07:30: allowed.
    $purchase($stranger)->assertSuccessful();
    $computer->update(['current_session_id' => null, 'current_session_user_id' => null, 'session_ends_at' => null]);

    // 06:25 + 60 min runs into the booking.
    Carbon::setTestNow(Carbon::parse('2026-10-15 06:25:00', 'UTC'));
    $purchase($stranger)->assertUnprocessable()->assertJsonValidationErrors('computer_id');

    $purchase($booked)->assertSuccessful();
    expect(ComputerReservation::query()->sole()->arrived_at)->not->toBeNull();
});

test('the club map shows a PC as reserved during its booking', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-15 06:00:00', 'UTC'));
    setBookingInterval(0);
    $this->actingAs(User::factory()->create());
    $computer = reservationComputer();
    $this->post(route('reservations.store'), reservationForm($computer, '2026-10-15T06:30'))->assertSessionHasNoErrors();

    $this->get(route('club-map.index'))->assertOk()->assertSee('cm-computer offline', false);

    Carbon::setTestNow(Carbon::parse('2026-10-15 06:45:00', 'UTC'));
    $this->get(route('club-map.index'))->assertOk()->assertSee('cm-computer reserved', false);
});

test('the club map shows every booked PC, also after a PC without a booking', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-15 06:00:00', 'UTC'));
    setBookingInterval(30);
    $this->actingAs(User::factory()->create());
    $first = reservationComputer(1);
    $second = reservationComputer(2);
    $this->post(route('reservations.store'), reservationForm($first, '2026-10-15T06:15'))->assertSessionHasNoErrors();
    $this->post(route('reservations.store'), reservationForm($second, '2026-10-15T06:15'))->assertSessionHasNoErrors();

    // The customer came to the first PC: only the second one stays reserved.
    $this->post(route('reservations.arrive', ComputerReservation::query()->where('club_computer_id', $first->id)->sole()))->assertSessionHasNoErrors();

    $this->get(route('club-map.index'))->assertOk()
        ->assertSee('class="cm-computer offline" data-id="'.$first->id.'"', false)
        ->assertSee('class="cm-computer reserved" data-id="'.$second->id.'"', false);
});
