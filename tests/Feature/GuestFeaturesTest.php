<?php

use App\Models\ActivityLog;
use App\Models\AgentQrLogin;
use App\Models\Club;
use App\Models\ClubComputer;
use App\Models\ClubMembership;
use App\Models\ClubZone;
use App\Models\ComputerReservation;
use App\Models\Friendship;
use App\Models\MemberBalance;
use App\Models\Tariff;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\ClubComputerSessions;
use App\Services\CurrentClub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

/** Switches the club's "Guests" settings. */
function guestSettings(array $guests): Club
{
    $club = Club::query()->findOrFail(app(CurrentClub::class)->id());
    $club->forceFill(['timezone' => 'UTC', 'settings' => ['guests' => $guests]])->save();
    app(CurrentClub::class)->set($club);

    return $club;
}

function allGuestFeatures(): Club
{
    return guestSettings(['show_qr_code_on_login' => true, 'allow_guest_self_transfer' => true, 'allow_adding_friends' => true, 'allow_balance_transfer_to_friends' => true]);
}

/** A customer's agent token request as the PC agent sends it. */
function guestApi(User $user): array
{
    app('auth')->forgetGuards();
    $token = $user->createToken('PC', ['club:'.app(CurrentClub::class)->id()])->plainTextToken;

    return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
}

function guestComputer(int $number, ?ClubZone $zone = null, bool $online = true): ClubComputer
{
    $zone ??= ClubZone::query()->firstOrCreate(['name' => 'Hall']);
    $computer = ClubComputer::query()->create(['club_zone_id' => $zone->id, 'number' => $number]);
    if ($online) {
        $computer->forceFill(['last_seen_at' => now()])->save();
    }

    return $computer;
}

test('the agent heartbeat reports the guest features of the club', function () {
    guestSettings(['allow_adding_friends' => true]);
    $token = app(ClubComputerSessions::class)->issueAgentToken(guestComputer(1));
    app(CurrentClub::class)->clear();

    $this->postJson(route('api.agent.heartbeat'), [], ['X-Agent-Token' => $token])->assertOk()
        ->assertJsonPath('club.guest_features', ['qr_login' => false, 'self_transfer' => false, 'friends' => true, 'balance_transfer' => false]);
});

test('customers add friends by phone, the other one accepts, and phones stay masked', function () {
    allGuestFeatures();
    $arman = User::factory()->create(['name' => 'Арман', 'phone' => '+77011112233']);
    $dana = User::factory()->create(['name' => 'Дана', 'phone' => '+77029998877']);

    $this->postJson(route('api.guest.friends.store'), ['phone' => '8 702 999 88 77'], guestApi($arman))
        ->assertCreated()
        ->assertJsonPath('outgoing.0.name', 'Дана')
        ->assertJsonPath('outgoing.0.phone', '+7 702 ••• •• 77');
    $this->postJson(route('api.guest.friends.store'), ['phone' => '+77029998877'], guestApi($arman))->assertJsonValidationErrors('phone');
    $this->postJson(route('api.guest.friends.store'), ['phone' => '+77011112233'], guestApi($arman))->assertJsonValidationErrors('phone');
    $this->postJson(route('api.guest.friends.store'), ['phone' => '+77000000000'], guestApi($arman))->assertJsonValidationErrors('phone');

    $friendship = Friendship::query()->sole();
    $this->postJson(route('api.guest.friends.accept', $friendship), [], guestApi($arman))->assertJsonValidationErrors('friendship');
    $this->getJson(route('api.guest.friends.index'), guestApi($dana))->assertOk()->assertJsonPath('incoming.0.name', 'Арман');
    $this->postJson(route('api.guest.friends.accept', $friendship), [], guestApi($dana))->assertOk()->assertJsonPath('friends.0.name', 'Арман');

    $this->getJson(route('api.guest.friends.index'), guestApi($arman))->assertOk()
        ->assertJsonPath('friends.0.name', 'Дана')
        ->assertJsonCount(0, 'incoming')
        ->assertJsonPath('features.friends', true);

    $this->postJson(route('api.guest.friends.remove', $friendship), [], guestApi($arman))->assertOk()->assertJsonCount(0, 'friends');
    expect(Friendship::query()->count())->toBe(0)
        ->and(ActivityLog::query()->where('event', 'friend.accepted')->exists())->toBeTrue();
});

test('a request to someone who already asked you makes you friends', function () {
    allGuestFeatures();
    $arman = User::factory()->create(['phone' => '+77011112233']);
    $dana = User::factory()->create(['phone' => '+77029998877']);

    $this->postJson(route('api.guest.friends.store'), ['phone' => '+77029998877'], guestApi($arman))->assertCreated();
    $this->postJson(route('api.guest.friends.store'), ['phone' => '+77011112233'], guestApi($dana))->assertCreated()->assertJsonCount(1, 'friends');

    expect(Friendship::query()->sole()->accepted_at)->not->toBeNull();
});

test('friends of another club are not found and the switch off closes adding friends', function () {
    guestSettings(['allow_adding_friends' => false]);
    $arman = User::factory()->create(['phone' => '+77011112233']);
    $outsider = User::factory()->create(['phone' => '+77029998877']);
    ClubMembership::query()->where('user_id', $outsider->id)->delete();

    $this->postJson(route('api.guest.friends.store'), ['phone' => '+77029998877'], guestApi($arman))->assertForbidden();

    guestSettings(['allow_adding_friends' => true]);
    $this->postJson(route('api.guest.friends.store'), ['phone' => '+77029998877'], guestApi($arman))->assertJsonValidationErrors('phone');
});

test('friends transfer money from the main balance only, within the balance', function () {
    allGuestFeatures();
    $arman = User::factory()->create(['phone' => '+77011112233']);
    $dana = User::factory()->create(['phone' => '+77029998877']);
    $stranger = User::factory()->create();
    MemberBalance::query()->where('user_id', $arman->id)->update(['balance' => 100, 'bonus_balance' => 50]);
    MemberBalance::query()->where('user_id', $dana->id)->update(['balance' => 10]);
    $friendship = Friendship::query()->create(['requester_id' => $arman->id, 'addressee_id' => $dana->id, 'accepted_at' => now()]);
    $pending = Friendship::query()->create(['requester_id' => $arman->id, 'addressee_id' => $stranger->id]);

    $this->postJson(route('api.guest.friends.transfer', $friendship), ['amount' => '100.01'], guestApi($arman))->assertJsonValidationErrors('amount');
    $this->postJson(route('api.guest.friends.transfer', $friendship), ['amount' => '0'], guestApi($arman))->assertJsonValidationErrors('amount');
    $this->postJson(route('api.guest.friends.transfer', $friendship), ['amount' => '-5'], guestApi($arman))->assertJsonValidationErrors('amount');
    $this->postJson(route('api.guest.friends.transfer', $pending), ['amount' => '5'], guestApi($arman))->assertJsonValidationErrors('friendship');
    $this->postJson(route('api.guest.friends.transfer', $friendship), ['amount' => '5'], guestApi($stranger))->assertJsonValidationErrors('friendship');

    $this->postJson(route('api.guest.friends.transfer', $friendship), ['amount' => '40,50'], guestApi($arman))->assertOk()
        ->assertJsonPath('balance', '59.50');

    expect(MemberBalance::query()->where('user_id', $arman->id)->first())
        ->balance->toBe('59.50')
        ->bonus_balance->toBe('50.00')
        ->and(MemberBalance::query()->where('user_id', $dana->id)->value('balance'))->toBe('50.50')
        ->and(ActivityLog::query()->where('event', 'balance.transferred')->sole()->details)->toBe(['amount' => '40.50', 'to_user_id' => $dana->id, 'friendship_id' => $friendship->id]);

    guestSettings(['allow_balance_transfer_to_friends' => false]);
    $this->postJson(route('api.guest.friends.transfer', $friendship), ['amount' => '1'], guestApi($arman))->assertForbidden();
});

test('a customer moves the rest of the session to a free PC where the tariff is valid', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-15 10:00:00', 'UTC'));
    allGuestFeatures();
    $customer = User::factory()->create();
    $hall = ClubZone::query()->create(['name' => 'Hall']);
    $vip = ClubZone::query()->create(['name' => 'VIP']);
    $from = guestComputer(1, $hall);
    $free = guestComputer(2, $hall);
    $busy = guestComputer(3, $hall);
    $offline = guestComputer(4, $hall, online: false);
    $otherZone = guestComputer(5, $vip);
    $reserved = guestComputer(6, $hall);
    $reserved->update(['is_reserved' => true]);
    $busy->update(['current_session_id' => 'other', 'current_session_user_id' => User::factory()->create()->id, 'session_ends_at' => now()->addHour()]);
    $from->update(['current_session_id' => 'sess-1', 'current_session_user_id' => $customer->id, 'session_ends_at' => now()->addMinutes(90)]);
    $tariff = Tariff::query()->create(['name' => 'Hall', 'duration_minutes' => 120, 'price' => 100, 'currency' => 'KZT', 'payment_method' => 'main_or_bonus', 'is_active' => true]);
    $tariff->zones()->syncWithPivotValues([$hall->id], ['club_id' => $hall->club_id]);
    app(ActivityLogger::class)->record('tariff.purchased', 'test', $customer->id, $tariff, ['session_id' => 'sess-1']);
    $deviceToken = app(ClubComputerSessions::class)->issueAgentToken($from);

    // Someone else's booking that starts before the rest of the session ends: grey on the map.
    $booked = guestComputer(7, $hall);
    ComputerReservation::query()->create(['club_computer_id' => $booked->id, 'customer_name' => 'X', 'starts_at' => now()->addHour(), 'ends_at' => now()->addHours(2)]);

    $map = $this->getJson(route('api.guest.seats.index', ['computer_id' => $from->id]), guestApi($customer))->assertOk()
        ->assertJsonPath('current_computer_id', $from->id)
        ->assertJsonCount(2, 'zones')
        ->assertJsonPath('zones.0.name', 'Hall');
    $available = collect($map->json('computers'))->pluck('available', 'id');
    expect($available->all())->toBe([
        $from->id => false, $free->id => true, $busy->id => false, $offline->id => false, $reserved->id => false, $booked->id => false, $otherZone->id => false,
    ]);
    expect(collect($map->json('computers'))->firstWhere('id', $free->id))->toMatchArray(['zone_id' => $hall->id, 'number' => 2, 'x' => 5, 'y' => 5]);

    $this->postJson(route('api.guest.seat-transfer'), ['computer_id' => $from->id, 'target_computer_id' => $free->id], guestApi($customer))->assertForbidden();
    $this->postJson(route('api.guest.seat-transfer'), ['computer_id' => $from->id, 'target_computer_id' => $busy->id], [...guestApi($customer), 'X-Agent-Token' => $deviceToken])
        ->assertJsonValidationErrors('target_computer_id');
    $this->postJson(route('api.guest.seat-transfer'), ['computer_id' => $from->id, 'target_computer_id' => $otherZone->id], [...guestApi($customer), 'X-Agent-Token' => $deviceToken])
        ->assertJsonValidationErrors('target_computer_id');
    $this->postJson(route('api.guest.seat-transfer'), ['computer_id' => $from->id, 'target_computer_id' => $free->id], [...guestApi(User::factory()->create()), 'X-Agent-Token' => $deviceToken])
        ->assertJsonValidationErrors('computer_id');

    $this->postJson(route('api.guest.seat-transfer'), ['computer_id' => $from->id, 'target_computer_id' => $free->id], [...guestApi($customer), 'X-Agent-Token' => $deviceToken])
        ->assertOk()
        ->assertJsonPath('computer.id', $free->id)
        ->assertJsonPath('session.id', 'sess-1');

    expect($from->fresh()->current_session_id)->toBeNull()
        ->and($free->fresh())
        ->current_session_id->toBe('sess-1')
        ->current_session_user_id->toBe($customer->id)
        ->and($free->fresh()->session_ends_at->equalTo(now()->addMinutes(90)))->toBeTrue()
        ->and(ActivityLog::query()->where('event', 'session.transferred')->exists())->toBeTrue();
    expect($offline->fresh()->current_session_id)->toBeNull();
});

test('seat transfer is closed when the club has it switched off', function () {
    guestSettings(['allow_guest_self_transfer' => false]);
    $customer = User::factory()->create();
    $from = guestComputer(1);

    $this->getJson(route('api.guest.seats.index', ['computer_id' => $from->id]), guestApi($customer))->assertForbidden();
    $this->postJson(route('api.guest.seat-transfer'), ['computer_id' => $from->id, 'target_computer_id' => 2], guestApi($customer))->assertForbidden();
});

test('a PC shows a QR code, the customer confirms on the phone and the PC gets the token once', function () {
    allGuestFeatures();
    $customer = User::factory()->create(['name' => 'Арман']);
    $computer = guestComputer(7);
    $deviceToken = app(ClubComputerSessions::class)->issueAgentToken($computer);
    app(CurrentClub::class)->clear();

    $created = $this->postJson(route('api.agent.qr-login.store'), [], ['X-Agent-Token' => $deviceToken])->assertCreated();
    $id = $created->json('id');
    $code = basename($created->json('url'));
    expect($created->json('url'))->toBe(route('pc-login.show', $code))
        ->and(AgentQrLogin::query()->withoutGlobalScope('club')->sole()->code_hash)->toBe(hash('sha256', $code));

    $poll = fn () => $this->postJson(route('api.agent.qr-login.poll'), ['id' => $id, 'device_name' => 'PC-07'], ['X-Agent-Token' => $deviceToken]);
    $poll()->assertOk()->assertJsonPath('status', 'pending');

    // Not signed in on the site: the sign-in page first, then back to the code.
    $this->get(route('pc-login.show', $code))->assertRedirect(route('auth.show-login'));

    $this->actingAs($customer)->get(route('pc-login.show', $code))->assertOk()->assertSee('Арман');
    $this->post(route('pc-login.approve', $code))->assertRedirect(route('pc-login.done'));
    $this->post(route('pc-login.approve', $code))->assertRedirect(route('pc-login.show', $code));

    app('auth')->forgetGuards();
    $approved = $poll()->assertOk()->assertJsonPath('status', 'approved')->assertJsonPath('user.name', 'Арман');
    app('auth')->forgetGuards();
    $this->getJson(route('api.me'), ['Authorization' => 'Bearer '.$approved->json('access_token')])->assertOk()->assertJsonPath('user_id', $customer->id);

    app('auth')->forgetGuards();
    $poll()->assertOk()->assertJsonPath('status', 'expired');
    expect(ActivityLog::query()->withoutGlobalScope('club')->where('event', 'auth.qr_login')->exists())->toBeTrue();
});

test('QR codes expire, belong to their PC and are only for members of the club', function () {
    allGuestFeatures();
    $outsider = User::factory()->create();
    ClubMembership::query()->where('user_id', $outsider->id)->delete();
    $computer = guestComputer(1);
    $other = guestComputer(2);
    $token = app(ClubComputerSessions::class)->issueAgentToken($computer);
    $otherToken = app(ClubComputerSessions::class)->issueAgentToken($other);
    app(CurrentClub::class)->clear();

    $created = $this->postJson(route('api.agent.qr-login.store'), [], ['X-Agent-Token' => $token])->assertCreated();
    $code = basename($created->json('url'));

    $this->postJson(route('api.agent.qr-login.poll'), ['id' => $created->json('id'), 'device_name' => 'x'], ['X-Agent-Token' => $otherToken])
        ->assertOk()->assertJsonPath('status', 'expired');

    $this->actingAs($outsider)->post(route('pc-login.approve', $code))->assertSessionHasErrors('code');

    Carbon::setTestNow(now()->addMinutes(6));
    $this->get(route('pc-login.show', $code))->assertOk()->assertSee('QR-код устарел');
});

test('the QR code is not issued when the club has it switched off', function () {
    guestSettings(['show_qr_code_on_login' => false]);
    $token = app(ClubComputerSessions::class)->issueAgentToken(guestComputer(1));
    app(CurrentClub::class)->clear();

    $this->postJson(route('api.agent.qr-login.store'), [], ['X-Agent-Token' => $token])->assertForbidden()->assertJsonPath('code', 'qr_disabled');
});

test('the agent shows the customer their own log: purchases, top-ups, transfers, seat changes and sign-ins', function () {
    allGuestFeatures();
    $staff = User::factory()->create(['name' => 'Кассир']);
    $arman = User::factory()->create(['name' => 'Арман', 'phone' => '+77011112233']);
    $dana = User::factory()->create(['name' => 'Дана', 'phone' => '+77029998877']);
    $activity = app(ActivityLogger::class);
    $tariff = Tariff::query()->create(['name' => 'Час', 'duration_minutes' => 60, 'price' => 500, 'currency' => 'KZT', 'payment_method' => 'main_or_bonus', 'is_active' => true]);

    $activity->record('user.balance_topped_up', 'x', $staff->id, $arman, ['amount' => 1000, 'balance_type' => 'основной баланс', 'payment_method' => 'наличные', 'new_balance' => 1000]);
    $activity->record('user.balance_topped_up', 'x', $staff->id, $arman, ['amount' => 200, 'balance_type' => 'бонусный баланс', 'payment_method' => 'карта', 'new_balance' => 200]);
    $activity->record('tariff.purchased', 'x', $arman->id, $tariff, ['amount' => '500.00', 'main_balance_amount' => '400.00', 'bonus_balance_amount' => '100.00', 'duration_minutes' => 60]);
    $activity->record('session.transferred', 'x', $arman->id, null, ['from' => 'PC-01', 'to' => 'PC-02']);
    $activity->record('user.balance_topped_up', 'x', $staff->id, $dana, ['amount' => 999, 'balance_type' => 'основной баланс', 'payment_method' => 'наличные', 'new_balance' => 999]);
    MemberBalance::query()->whereIn('user_id', [$arman->id, $dana->id])->update(['balance' => 500]);
    $friendship = Friendship::query()->create(['requester_id' => $arman->id, 'addressee_id' => $dana->id, 'accepted_at' => now()]);
    $this->postJson(route('api.guest.friends.transfer', $friendship), ['amount' => '50'], guestApi($dana))->assertOk();

    $entries = $this->getJson(route('api.guest.history'), guestApi($arman))->assertOk()
        ->assertJsonPath('next_before', null)
        ->json('data');

    expect(collect($entries)->map(fn (array $entry): array => [$entry['kind'], $entry['title'], $entry['amount'], $entry['bonus_amount']])->all())->toBe([
        ['transfer_in', 'Перевод от друга Дана', '50.00', null],
        ['seat', 'Пересадка на ПК PC-02', null, null],
        ['purchase', 'Покупка тарифа «Час»', '-400.00', '-100.00'],
        ['top_up', 'Пополнение на кассе', null, '200.00'],
        ['top_up', 'Пополнение на кассе', '1000.00', null],
    ]);

    $this->getJson(route('api.guest.history'), guestApi($dana))->assertOk()
        ->assertJsonPath('data.0.kind', 'transfer_out')
        ->assertJsonPath('data.0.title', 'Перевод другу Арман')
        ->assertJsonPath('data.0.amount', '-50.00')
        ->assertJsonCount(2, 'data');
});

test('the customer log comes in pages', function () {
    $user = User::factory()->create();
    foreach (range(1, 35) as $i) {
        app(ActivityLogger::class)->record('auth.api_login', 'x', $user->id, $user, ['device' => "PC-$i"]);
    }

    $first = $this->getJson(route('api.guest.history'), guestApi($user))->assertOk()->assertJsonCount(30, 'data');
    expect($first->json('data.0.note'))->toBe('PC-35');

    $this->getJson(route('api.guest.history', ['before' => $first->json('next_before')]), guestApi($user))->assertOk()
        ->assertJsonCount(5, 'data')
        ->assertJsonPath('next_before', null)
        ->assertJsonPath('data.4.note', 'PC-1');
});
