<?php

use App\Models\ChatMessage;
use App\Models\Club;
use App\Models\ClubMembership;
use App\Models\SaasPlan;
use App\Models\User;
use App\Services\ClubNotifications;
use App\Services\CurrentClub;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function chatUser(string $role, string $name): User
{
    $user = User::factory()->create(['name' => $name]);
    ClubMembership::query()->where('user_id', $user->id)->update(['role' => $role]);

    return $user;
}

function chatAgent(User $user): array
{
    app('auth')->forgetGuards();
    app(CurrentClub::class)->clear();
    $clubId = ClubMembership::query()->withoutGlobalScope('club')->where('user_id', $user->id)->value('club_id');

    return ['Authorization' => 'Bearer '.$user->createToken('PC', ['club:'.$clubId])->plainTextToken, 'Accept' => 'application/json'];
}

test('a customer writes from the agent; the cashier sees it, answers, and the customer gets the answer', function () {
    $customer = chatUser('client', 'Арман');
    $cashier = chatUser('cashier', 'Айгерим');
    $admin = chatUser('admin', 'Админ');

    $this->postJson(route('api.guest.chat.store'), ['body' => 'Можно колу к ПК 5?'], chatAgent($customer))->assertCreated()
        ->assertJsonPath('message.from_staff', false);

    app('auth')->forgetGuards();
    app(CurrentClub::class)->clear();
    $this->actingAs($admin)->get(route('chat.index'))->assertOk()->assertSee('Арман')->assertSee('Можно колу к ПК 5?')->assertDontSee('Ответ клиенту…');
    expect(ChatMessage::query()->whereNull('read_at')->count())->toBe(1);
    $this->post(route('chat.reply', $customer), ['body' => 'x'])->assertForbidden();

    $membership = ClubMembership::query()->where('user_id', $cashier->id)->sole();
    expect(app(ClubNotifications::class)->for($membership)->pluck('id')->first(fn ($id) => str_starts_with($id, 'chat-')))->not->toBeNull();

    $this->actingAs($cashier)->get(route('chat.index'))->assertOk()->assertSee('Ответ клиенту…');
    expect(ChatMessage::query()->where('from_staff', false)->sole()->read_at)->not->toBeNull();
    $this->postJson(route('chat.reply', $customer), ['body' => 'Сейчас принесём'])->assertCreated()
        ->assertJsonPath('message.author', 'Айгерим');

    $this->getJson(route('api.guest.chat'), chatAgent($customer))->assertOk()
        ->assertJsonCount(2, 'messages')
        ->assertJsonPath('messages.1.body', 'Сейчас принесём')
        ->assertJsonPath('messages.1.from_staff', true);
    $after = ChatMessage::query()->max('id');
    $this->getJson(route('api.guest.chat', ['after' => $after]), chatAgent($customer))->assertOk()->assertJsonCount(0, 'messages');
});

test('customers see only their own conversation and staff of another club do not see it', function () {
    $customer = chatUser('client', 'Арман');
    $other = chatUser('client', 'Дана');
    ChatMessage::query()->create(['customer_id' => $other->id, 'author_id' => $other->id, 'from_staff' => false, 'body' => 'Секрет Даны']);

    $this->getJson(route('api.guest.chat'), chatAgent($customer))->assertOk()->assertJsonCount(0, 'messages');

    // A cashier of another club: the conversation is not theirs to see.
    $foreignClub = Club::query()->create(['name' => 'Other', 'slug' => 'other']);
    $foreignClub->subscription()->create(['saas_plan_id' => SaasPlan::query()->value('id'), 'status' => 'active', 'started_at' => now(), 'current_period_ends_at' => now()->addMonth()]);
    $foreignCashier = User::factory()->create();
    ClubMembership::query()->where('user_id', $foreignCashier->id)->delete();
    ClubMembership::query()->withoutGlobalScope('club')->forceCreate(['club_id' => $foreignClub->id, 'user_id' => $foreignCashier->id, 'role' => 'cashier', 'discount' => 0]);
    app(CurrentClub::class)->clear();
    $this->actingAs($foreignCashier)->get(route('chat.index'))->assertOk()->assertDontSee('Секрет Даны');
});
