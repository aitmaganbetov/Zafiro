<?php

use App\Models\ActivityLog;
use App\Models\ChatMessage;
use App\Models\Club;
use App\Models\ClubComputer;
use App\Models\ClubMembership;
use App\Models\ClubZone;
use App\Models\Friendship;
use App\Models\MemberBalance;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\ClubComputerSessions;
use App\Services\CurrentClub;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Merges settings sections into the current club's settings. */
function shopClubSettings(array $sections): void
{
    $club = Club::query()->findOrFail(app(CurrentClub::class)->id());
    $club->forceFill(['settings' => array_replace_recursive($club->settings ?? [], $sections)])->save();
    app(CurrentClub::class)->set($club);
}

/** The customer's agent request from the PC (bearer token + the PC's device token). */
function shopRequest(User $customer, string $deviceToken): array
{
    app('auth')->forgetGuards();
    $token = $customer->createToken('PC', ['club:'.app(CurrentClub::class)->id()])->plainTextToken;

    return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json', 'X-Agent-Token' => $deviceToken];
}

function shopSetup(): array
{
    User::factory()->create(); // the club owner
    $customer = User::factory()->create(['name' => 'Арман']);
    ClubMembership::query()->where('user_id', $customer->id)->update(['role' => 'client']);
    MemberBalance::query()->where('user_id', $customer->id)->update(['balance' => 1000, 'bonus_balance' => 300]);
    $computer = ClubComputer::query()->create(['club_zone_id' => ClubZone::query()->create(['name' => 'Hall'])->id, 'number' => 5]);
    $token = app(ClubComputerSessions::class)->issueAgentToken($computer);
    $cola = Product::query()->create(['sku' => 'COLA', 'name' => 'Cola', 'category' => 'Напитки', 'price' => 350, 'quantity' => 10]);
    $chips = Product::query()->create(['sku' => 'CHIPS', 'name' => 'Чипсы', 'category' => 'Еда', 'price' => 250.5, 'quantity' => 1]);

    return [$customer, $computer, $token, $cola, $chips];
}

test('a customer pays for goods in the agent from the main balance and the staff get the order', function () {
    [$customer, $computer, $token, $cola, $chips] = shopSetup();

    $this->postJson(route('api.guest.shop.purchase'), ['computer_id' => $computer->id, 'items' => [['id' => $cola->id, 'quantity' => 2], ['id' => $chips->id, 'quantity' => 1]]], shopRequest($customer, $token))
        ->assertOk()
        ->assertJsonPath('amount', '950.50')
        ->assertJsonPath('balance', '49.50');

    expect(MemberBalance::query()->where('user_id', $customer->id)->first())
        ->balance->toBe('49.50')
        ->bonus_balance->toBe('300.00')
        ->and($cola->fresh()->quantity)->toBe(8)
        ->and($chips->fresh()->quantity)->toBe(0)
        ->and(StockMovement::query()->where('note', 'Заказ из агента')->count())->toBe(2)
        ->and(ChatMessage::query()->sole())
        ->customer_id->toBe($customer->id)
        ->from_staff->toBeFalse()
        ->club_computer_id->toBe($computer->id)
        ->body->toContain('Cola × 2')
        ->and(ActivityLog::query()->where('event', 'shop.purchased')->sole()->details['amount'])->toBe('950.50');

    // The order is in the customer's history, also under the "purchases" filter.
    $this->getJson(route('api.guest.history', ['filter' => 'purchases']), shopRequest($customer, $token))->assertOk()
        ->assertJsonPath('data.0.kind', 'shop')
        ->assertJsonPath('data.0.amount', '-950.50')
        ->assertJsonPath('data.0.note', 'Cola × 2, Чипсы × 1');
});

test('goods are not sold beyond the stock or the main balance, and nothing is charged then', function () {
    [$customer, $computer, $token, $cola, $chips] = shopSetup();

    $this->postJson(route('api.guest.shop.purchase'), ['computer_id' => $computer->id, 'items' => [['id' => $chips->id, 'quantity' => 2]]], shopRequest($customer, $token))
        ->assertJsonValidationErrors('items');
    // Bonuses do not pay for goods: 3 × 350 is more than the main balance.
    $this->postJson(route('api.guest.shop.purchase'), ['computer_id' => $computer->id, 'items' => [['id' => $cola->id, 'quantity' => 3]]], shopRequest($customer, $token))
        ->assertJsonValidationErrors('balance');
    $this->postJson(route('api.guest.shop.purchase'), ['computer_id' => $computer->id, 'items' => [['id' => 999999, 'quantity' => 1]]], shopRequest($customer, $token))
        ->assertJsonValidationErrors('items');
    // Only from the PC itself.
    $this->postJson(route('api.guest.shop.purchase'), ['computer_id' => $computer->id, 'items' => [['id' => $cola->id, 'quantity' => 1]]], shopRequest($customer, 'zag_wrong'))
        ->assertForbidden()->assertJsonPath('code', 'wrong_computer');

    expect(MemberBalance::query()->where('user_id', $customer->id)->value('balance'))->toBe('1000.00')
        ->and($cola->fresh()->quantity)->toBe(10)
        ->and(ChatMessage::query()->count())->toBe(0);
});

test('the club can turn the shop in the agent off', function () {
    [$customer, $computer, $token, $cola] = shopSetup();
    shopClubSettings(['club' => ['agent_shop_enabled' => false]]);
    app(CurrentClub::class)->clear();

    $this->postJson(route('api.agent.heartbeat'), [], ['X-Agent-Token' => $token])->assertOk()
        ->assertJsonPath('club.guest_features.shop', false);
    $this->postJson(route('api.guest.shop.purchase'), ['computer_id' => $computer->id, 'items' => [['id' => $cola->id, 'quantity' => 1]]], shopRequest($customer, $token))
        ->assertForbidden()->assertJsonPath('code', 'shop_disabled');
});

test('a transfer to a friend needs the account password', function () {
    shopClubSettings(['guests' => ['allow_balance_transfer_to_friends' => true]]);
    $arman = User::factory()->create(['phone' => '+77011112233']);
    $dana = User::factory()->create(['phone' => '+77029998877']);
    MemberBalance::query()->where('user_id', $arman->id)->update(['balance' => 100]);
    $friendship = Friendship::query()->create(['requester_id' => $arman->id, 'addressee_id' => $dana->id, 'accepted_at' => now()]);
    $headers = fn () => shopRequest($arman, 'unused');

    $this->postJson(route('api.guest.friends.transfer', $friendship), ['amount' => '10'], $headers())->assertJsonValidationErrors('password');
    $this->postJson(route('api.guest.friends.transfer', $friendship), ['amount' => '10', 'password' => 'wrong'], $headers())->assertJsonValidationErrors('password');
    expect(MemberBalance::query()->where('user_id', $arman->id)->value('balance'))->toBe('100.00');

    $this->postJson(route('api.guest.friends.transfer', $friendship), ['amount' => '10', 'password' => 'password'], $headers())->assertOk()
        ->assertJsonPath('balance', '90.00');
});

test('the customer history filters purchases, top-ups, transfers and visits', function () {
    $staff = User::factory()->create();
    $customer = User::factory()->create();
    $activity = app(ActivityLogger::class);
    $activity->record('user.balance_topped_up', 'x', $staff->id, $customer, ['amount' => 500, 'balance_type' => 'основной баланс', 'payment_method' => 'наличные', 'new_balance' => 500]);
    $activity->record('auth.api_login', 'x', $customer->id, $customer, ['device' => 'PC-1']);
    $activity->record('time.purchased', 'x', $customer->id, null, ['amount' => '100.00', 'duration_minutes' => 30]);
    $headers = shopRequest($customer, 'unused');

    $kinds = fn (?string $filter) => collect($this->getJson(route('api.guest.history', array_filter(['filter' => $filter])), $headers)->assertOk()->json('data'))->pluck('kind')->all();

    expect($kinds(null))->toBe(['purchase', 'login', 'top_up'])
        ->and($kinds('purchases'))->toBe(['purchase'])
        ->and($kinds('top_ups'))->toBe(['top_up'])
        ->and($kinds('visits'))->toBe(['login'])
        ->and($kinds('transfers'))->toBe([]);
    $this->getJson(route('api.guest.history', ['filter' => 'all-of-it']), $headers)->assertJsonValidationErrors('filter');
});

test('a restart of the PC resets a guest session, a later restart of the agent does not', function () {
    User::factory()->create();
    $computer = ClubComputer::query()->create(['club_zone_id' => ClubZone::query()->create(['name' => 'Hall'])->id, 'number' => 3]);
    $token = app(ClubComputerSessions::class)->issueAgentToken($computer);
    $guest = fn () => $computer->forceFill(['current_session_id' => 'guest-1', 'current_session_user_id' => null, 'session_guest' => true, 'session_ends_at' => now()->addHour()])->save();
    $guest();
    app(CurrentClub::class)->clear();

    // An ordinary report keeps the guest time.
    $this->postJson(route('api.agent.heartbeat'), [], ['X-Agent-Token' => $token])->assertOk()->assertJsonPath('session.guest', true);
    // The first report after Windows started ends it.
    $this->postJson(route('api.agent.heartbeat'), ['fresh_boot' => true], ['X-Agent-Token' => $token])->assertOk()->assertJsonPath('session', null);

    expect($computer->fresh())->session_guest->toBeFalse()->session_ends_at->toBeNull()
        ->and(ActivityLog::query()->withoutGlobalScopes()->where('event', 'computer.guest_session_reset')->count())->toBe(1);

    // A customer's own session is not touched by the signal.
    $customer = User::factory()->create();
    $computer->fresh()->forceFill(['current_session_id' => 'paid-1', 'current_session_user_id' => $customer->id, 'session_guest' => false, 'session_ends_at' => now()->addHour()])->save();
    $this->postJson(route('api.agent.heartbeat'), ['fresh_boot' => true], ['X-Agent-Token' => $token])->assertOk()->assertJsonPath('session.id', 'paid-1');
});

test('guest mode can be turned off: no "start tariff" on the map and the cash register refuses it', function () {
    $owner = User::factory()->create();
    $computer = ClubComputer::query()->create(['club_zone_id' => ClubZone::query()->create(['name' => 'Hall'])->id, 'number' => 1]);

    $this->actingAs($owner)->get(route('club-map.index'))->assertOk()->assertSee('guest-session-open', false);

    shopClubSettings(['guests' => ['guest_mode_enabled' => false]]);
    $this->actingAs($owner)->get(route('club-map.index'))->assertOk()->assertDontSee('guest-session-open"', false);
    $this->actingAs($owner)->postJson(route('club-computers.guest-session'), ['computer_ids' => [$computer->id], 'minutes' => 30, 'payment_method' => 'cash'])
        ->assertForbidden();
});

test('the club sets the shop, the sign-out without a session and guest mode, and the agent gets them', function () {
    $owner = User::factory()->create();
    $token = app(ClubComputerSessions::class)->issueAgentToken(ClubComputer::query()->create(['club_zone_id' => ClubZone::query()->create(['name' => 'Hall'])->id, 'number' => 1]));

    $this->actingAs($owner)->get(route('settings.club'))->assertOk()
        ->assertSee('Если нет активной сессии, через сколько отлогинить пользователя (в минутах)')
        ->assertSee('Включить магазин в агенте');
    $this->actingAs($owner)->post(route('settings.save'), [
        'section' => 'club',
        'session_idle_timeout' => 15,
        'pc_restart_after_session_end' => 0.5,
        'session_auto_terminate_when_pc_unavailable' => 5,
        'booking_interval' => 30,
        'agent_idle_logout_minutes' => 10,
        'agent_shop_enabled' => '0',
    ])->assertRedirect();
    $this->actingAs($owner)->post(route('settings.save'), ['section' => 'guests', 'guest_mode_enabled' => '0'])->assertRedirect();

    $settings = Club::query()->findOrFail(app(CurrentClub::class)->id())->settings;
    expect($settings['club']['agent_idle_logout_minutes'])->toBe(10)
        ->and($settings['club']['agent_shop_enabled'])->toBeFalse()
        ->and($settings['guests']['guest_mode_enabled'])->toBeFalse();

    app(CurrentClub::class)->clear();
    $this->postJson(route('api.agent.heartbeat'), [], ['X-Agent-Token' => $token])->assertOk()
        ->assertJsonPath('club.idle_logout_minutes', 10)
        ->assertJsonPath('club.guest_features.shop', false);
});
