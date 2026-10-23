<?php

use App\Models\ChatMessage;
use App\Models\Club;
use App\Models\ClubComputer;
use App\Models\ClubMembership;
use App\Models\ClubZone;
use App\Models\MemberBalance;
use App\Models\Product;
use App\Models\ShopOrder;
use App\Models\Tariff;
use App\Models\User;
use App\Services\CurrentClub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

/** A member of the current club with the role; returns [user, Bearer headers of a staff-app token]. */
function staffApiMember(string $role, array $attributes = []): array
{
    $user = User::factory()->create($attributes);
    ClubMembership::query()->where('user_id', $user->id)->update(['role' => $role]);
    app('auth')->forgetGuards();
    $token = $user->createToken('Zafiro Staff', ['club:'.app(CurrentClub::class)->id()])->plainTextToken;

    return [$user, ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json']];
}

/** A request of the staff app as the token's user: the guard of the previous request is forgotten. */
function staffApi(string $method, string $uri, array $headers, array $data = [])
{
    app('auth')->forgetGuards();

    return test()->json($method, $uri, $data, $headers);
}

function staffApiComputer(int $number = 1): ClubComputer
{
    $zone = ClubZone::query()->firstOrCreate(['name' => 'Hall'], ['price_per_hour' => 600]);

    return ClubComputer::query()->create(['club_zone_id' => $zone->id, 'number' => $number, 'last_seen_at' => now()]);
}

test('only staff use the staff API; me tells the role, permissions and counters', function () {
    [, $client] = staffApiMember('client');
    staffApi('GET', route('api.staff.me'), $client)->assertForbidden();
    staffApi('GET', route('api.staff.computers.index'), $client)->assertForbidden();
    staffApi('GET', route('api.staff.me'), ['Accept' => 'application/json'])->assertUnauthorized();

    [, $cashier] = staffApiMember('cashier', ['name' => 'Кассир']);
    staffApi('GET', route('api.staff.me'), $cashier)->assertOk()
        ->assertJsonPath('role', 'cashier')
        ->assertJsonPath('user.name', 'Кассир')
        ->assertJsonPath('club.currency', 'KZT')
        ->assertJsonPath('permissions', ['cash' => true, 'guest_session' => true, 'end_session' => false, 'control' => true, 'chat_reply' => true, 'stock_receive' => true, 'stock_manage' => false, 'reports' => false])
        ->assertJsonPath('counters.orders_new', 0)
        ->assertJsonPath('shift', null);

    [, $operator] = staffApiMember('operator');
    staffApi('GET', route('api.staff.me'), $operator)->assertOk()
        ->assertJsonPath('permissions.cash', false)->assertJsonPath('permissions.end_session', true);
    // Narrower sections follow the panel's roles.
    staffApi('GET', route('api.staff.cash.show'), $operator)->assertForbidden();
    staffApi('GET', route('api.staff.reports.today'), $cashier)->assertForbidden();
    staffApi('POST', route('api.staff.stock.movements.store'), $cashier, [])->assertForbidden();
});

test('the sign-in tells the role in each club, so the app keeps the staff ones', function () {
    $user = User::factory()->create(['phone' => '+77005550011', 'password' => Hash::make('staff-pass')]);
    ClubMembership::query()->where('user_id', $user->id)->update(['role' => 'cashier']);
    app(CurrentClub::class)->clear();

    $this->postJson(route('api.auth.login'), ['phone' => '+77005550011', 'password' => 'staff-pass', 'device_name' => 'Zafiro Staff'])->assertOk()
        ->assertJsonPath('user.role', 'cashier')
        ->assertJsonPath('clubs.0.role', 'cashier');
});

test('the club map shows PCs with sessions, staff visits and tariffs; control and end session use the panel actions', function () {
    $customer = User::factory()->create(['name' => 'Ерлан']);
    $busy = staffApiComputer(1);
    $busy->forceFill(['current_session_id' => 'sess-1', 'current_session_user_id' => $customer->id, 'session_ends_at' => now()->addHour(), 'agent_state' => 'in_session', 'agent_version' => '0.0.61'])->save();
    $free = staffApiComputer(2);
    $free->forceFill(['last_seen_at' => now()->subMinutes(10)])->save();
    $tariff = Tariff::query()->create(['name' => 'Час', 'duration_minutes' => 60, 'price' => 800, 'currency' => 'KZT', 'payment_method' => 'main_or_bonus', 'is_active' => true]);
    [, $operator] = staffApiMember('operator');

    staffApi('GET', route('api.staff.computers.index'), $operator)->assertOk()
        ->assertJsonPath('computers.0.session.user.name', 'Ерлан')
        ->assertJsonPath('computers.0.connection', 'online')
        ->assertJsonPath('computers.0.agent_version', '0.0.61')->assertJsonPath('computers.1.agent_version', null)
        ->assertJsonPath('computers.1.session', null)
        ->assertJsonPath('computers.1.connection', 'offline')
        ->assertJsonPath('tariffs.0.id', $tariff->id)
        ->assertJsonPath('tariffs.0.price', '800.00')
        ->assertJsonPath('guest_mode', true);

    staffApi('POST', route('api.staff.computers.control'), $operator, ['computer_ids' => [$free->id], 'action' => 'reserve'])->assertOk()->assertJsonPath('reserved', true);
    expect($free->fresh()->is_reserved)->toBeTrue();

    // A cashier may not end a customer's session (as on the panel); an operator may.
    [, $cashier] = staffApiMember('cashier');
    staffApi('POST', route('api.staff.computers.end-session', $busy), $cashier, ['session_id' => 'sess-1'])->assertForbidden();
    staffApi('POST', route('api.staff.computers.end-session', $busy), $operator, ['session_id' => 'sess-1'])->assertOk();
    expect($busy->fresh()->current_session_id)->toBeNull();
});

test('a cashier works the whole shift: open, top-up with a bonus, sale, cash out, close with a Z-report', function () {
    $club = Club::query()->findOrFail(app(CurrentClub::class)->id());
    $club->forceFill(['settings' => ['finances' => ['topup_bonus_enabled' => true, 'topup_bonus_levels' => [['amount' => 1000, 'percent' => 10]]]]])->save();
    app(CurrentClub::class)->set($club);
    $customer = User::factory()->create(['name' => 'Гость']);
    ClubMembership::query()->where('user_id', $customer->id)->update(['role' => 'client']);
    $cola = Product::query()->create(['sku' => 'COLA', 'name' => 'Cola', 'category' => 'Напитки', 'price' => 350, 'quantity' => 10]);
    [$cashierUser, $cashier] = staffApiMember('cashier');

    staffApi('GET', route('api.staff.cash.show'), $cashier)->assertOk()->assertJsonPath('shift', null)->assertJsonPath('transactions', []);
    staffApi('POST', route('api.staff.cash.top-ups.store'), $cashier, ['user_id' => $customer->id, 'amount' => 1000, 'balance_type' => 'balance', 'payment_method' => 'cash'])
        ->assertUnprocessable();

    $shiftId = staffApi('POST', route('api.staff.cash.shifts.open'), $cashier, ['opening_balance' => 5000])->assertCreated()->json('shift.id');
    staffApi('POST', route('api.staff.cash.top-ups.store'), $cashier, ['user_id' => $customer->id, 'amount' => 2000, 'balance_type' => 'balance', 'payment_method' => 'card'])->assertOk()
        ->assertJsonPath('bonus', '200.00')
        ->assertJsonPath('balance', '2000.00')
        ->assertJsonPath('bonus_balance', '200.00');
    staffApi('POST', route('api.staff.cash.sales.store'), $cashier, ['items' => [['product_id' => $cola->id, 'quantity' => 2]]])->assertCreated()
        ->assertJsonPath('amount', '700.00')->assertJsonPath('items.0.total', '700.00');
    staffApi('POST', route('api.staff.cash.transactions.store'), $cashier, ['type' => 'expense', 'amount' => 100, 'description' => 'Вода'])->assertCreated();
    staffApi('POST', route('api.staff.cash.transactions.store'), $cashier, ['type' => 'expense', 'amount' => 99999, 'description' => 'Много'])->assertUnprocessable();

    staffApi('GET', route('api.staff.me'), $cashier)->assertJsonPath('shift.id', $shiftId)->assertJsonPath('shift.balance', '7600.00');
    staffApi('GET', route('api.staff.cash.show'), $cashier)->assertOk()
        ->assertJsonPath('totals', ['income' => '2700.00', 'expense' => '100.00', 'balance' => '7600.00'])
        ->assertJsonPath('report.items.0.name', 'Cola')
        ->assertJsonCount(3, 'transactions');

    staffApi('POST', route('api.staff.cash.shifts.close', $shiftId), $cashier, [])->assertOk()
        ->assertJsonPath('shift.closing_balance', '7600.00')
        ->assertJsonPath('report.staff.0.name', $cashierUser->name);
    staffApi('GET', route('api.staff.cash.show'), $cashier)->assertJsonPath('shift', null);
});

test('customers are searched by name or phone, with balances and the discount in force', function () {
    $erlan = User::factory()->create(['name' => 'Ерлан Сейтов', 'phone' => '+77011112233']);
    ClubMembership::query()->where('user_id', $erlan->id)->update(['role' => 'client', 'discount' => 5]);
    MemberBalance::query()->where('user_id', $erlan->id)->update(['balance' => 500, 'bonus_balance' => 50]);
    $other = User::factory()->create(['name' => 'Айгерим', 'phone' => '+77029998877']);
    ClubMembership::query()->where('user_id', $other->id)->update(['role' => 'client']);
    [, $operator] = staffApiMember('operator');

    staffApi('GET', route('api.staff.customers.index', ['search' => 'Ерлан']), $operator)->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0', ['user_id' => $erlan->id, 'name' => 'Ерлан Сейтов', 'phone' => '+77011112233', 'balance' => '500.00', 'bonus_balance' => '50.00', 'discount' => '5.00', 'discount_cumulative' => false, 'total_spent' => '0.00']);
    staffApi('GET', route('api.staff.customers.index', ['search' => '8 702 999']), $operator)->assertOk()->assertJsonPath('data.0.user_id', $other->id);
    // Staff are not customers.
    staffApi('GET', route('api.staff.customers.index'), $operator)->assertOk()->assertJsonCount(2, 'data');
});

test('goods: everyone receives, managers also write off', function () {
    $cola = Product::query()->create(['sku' => 'COLA', 'name' => 'Cola', 'category' => 'Напитки', 'price' => 350, 'quantity' => 3]);
    [, $operator] = staffApiMember('operator');
    [, $manager] = staffApiMember('manager');

    staffApi('GET', route('api.staff.products.index', ['search' => 'col']), $operator)->assertOk()
        ->assertJsonPath('data.0.quantity', 3)->assertJsonPath('data.0.price', '350.00');
    staffApi('POST', route('api.staff.stock.receive'), $operator, ['product_id' => $cola->id, 'quantity' => 5])->assertOk()->assertJsonPath('quantity', 8);
    staffApi('POST', route('api.staff.stock.movements.store'), $operator, ['product_id' => $cola->id, 'type' => 'write_off', 'quantity' => 2])->assertForbidden();
    staffApi('POST', route('api.staff.stock.movements.store'), $manager, ['product_id' => $cola->id, 'type' => 'write_off', 'quantity' => 2])->assertOk()->assertJsonPath('quantity', 6);
    staffApi('POST', route('api.staff.stock.movements.store'), $manager, ['product_id' => $cola->id, 'type' => 'write_off', 'quantity' => 99])->assertUnprocessable();
});

test('orders from PCs are listed and handed over or cancelled once', function () {
    $customer = User::factory()->create(['name' => 'Арман']);
    MemberBalance::query()->where('user_id', $customer->id)->update(['balance' => 0]);
    $order = ShopOrder::query()->create(['user_id' => $customer->id, 'computer_name' => 'PC 5', 'items' => [['product_id' => 999, 'name' => 'Cola', 'quantity' => 2, 'price' => '350.00', 'total' => '700.00']], 'amount' => '700.00', 'status' => ShopOrder::STATUS_NEW]);
    $second = ShopOrder::query()->create(['user_id' => $customer->id, 'computer_name' => 'PC 6', 'items' => [], 'amount' => '100.00', 'status' => ShopOrder::STATUS_NEW]);
    [, $operator] = staffApiMember('operator', ['name' => 'Оператор']);

    staffApi('GET', route('api.staff.orders.index'), $operator)->assertOk()
        ->assertJsonPath('new_count', 2)
        ->assertJsonPath('data.1.items.0.name', 'Cola')
        ->assertJsonPath('data.1.customer.name', 'Арман');
    staffApi('POST', route('api.staff.orders.done', $order), $operator, [])->assertOk()->assertJsonPath('status', 'done')->assertJsonPath('handled_by', 'Оператор');
    staffApi('POST', route('api.staff.orders.done', $order), $operator, [])->assertUnprocessable()->assertJsonValidationErrors('order');
    staffApi('POST', route('api.staff.orders.cancel', $second), $operator, [])->assertOk()->assertJsonPath('status', 'cancelled');
    expect(MemberBalance::query()->where('user_id', $customer->id)->value('balance'))->toBe('100.00');
    staffApi('GET', route('api.staff.orders.index', ['status' => 'all']), $operator)->assertJsonCount(2, 'data')->assertJsonPath('new_count', 0);
});

test('chat: everyone reads, cashiers and operators answer; notifications and the day report', function () {
    $customer = User::factory()->create(['name' => 'Гость']);
    ChatMessage::query()->create(['customer_id' => $customer->id, 'from_staff' => false, 'body' => 'Помогите']);
    [, $manager] = staffApiMember('manager');
    [, $operator] = staffApiMember('operator');

    staffApi('GET', route('api.staff.chats.index'), $manager)->assertOk()
        ->assertJsonPath('conversations.0.customer.name', 'Гость')
        ->assertJsonPath('conversations.0.unread', 1)
        ->assertJsonPath('conversations.0.last.body', 'Помогите');
    staffApi('POST', route('api.staff.chat.reply', $customer), $manager, ['body' => 'Иду'])->assertForbidden();
    staffApi('GET', route('api.staff.chat.poll', ['customer' => $customer->id]), $operator)->assertOk()
        ->assertJsonPath('messages.0.body', 'Помогите')->assertJsonPath('unread', 0);
    staffApi('POST', route('api.staff.chat.reply', $customer), $operator, ['body' => 'Иду'])->assertCreated()->assertJsonPath('message.from_staff', true);

    staffApi('GET', route('api.staff.notifications.index'), $operator)->assertOk()->assertJsonStructure(['unread_count', 'items']);
    staffApi('POST', route('api.staff.notifications.seen'), $operator, [])->assertOk()->assertJsonPath('unread_count', 0);

    Product::query()->create(['sku' => 'LOW', 'name' => 'Чипсы', 'category' => 'Еда', 'price' => 200, 'quantity' => 2]);
    staffApiComputer(1);
    staffApi('GET', route('api.staff.reports.today'), $manager)->assertOk()
        ->assertJsonCount(7, 'week')
        ->assertJsonPath('today.income', '0.00')
        ->assertJsonPath('computers.total', 1)
        ->assertJsonPath('computers.online', 1)
        ->assertJsonPath('low_stock.0.name', 'Чипсы')
        ->assertJsonPath('week.6.date', now(app(CurrentClub::class)->get()->timezone)->toDateString());
});
