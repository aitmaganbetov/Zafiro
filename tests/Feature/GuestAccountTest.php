<?php

use App\Models\ActivityLog;
use App\Models\ClubMembership;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\CurrentClub;
use App\Services\Telegram;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const BOT_TOKEN = '123456789:AAEexampleexampleexampleexample123';

function guestWithRole(string $role, array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    ClubMembership::query()->where('user_id', $user->id)->update(['role' => $role]);

    return $user;
}

function membershipOf(User $user): ClubMembership
{
    return ClubMembership::query()->where('user_id', $user->id)->sole();
}

function withBot(): void
{
    PlatformSetting::write(Telegram::SETTING, ['token' => Crypt::encryptString(BOT_TOKEN), 'username' => 'ZafiroBot'], null);
}

test('a cashier edits the name and email of a customer, not the discount', function () {
    $cashier = guestWithRole('cashier');
    $guest = guestWithRole('client', ['name' => 'Арман', 'email' => 'old@example.com']);

    $this->actingAs($cashier)->get(route('users.index'))->assertOk()->assertSee('Изменить')->assertSee('Сбросить пароль');
    $this->patch(route('users.update', membershipOf($guest)), ['name' => 'Арман Сейтов', 'email' => 'new@example.com'])->assertSessionHasNoErrors();
    $this->patch(route('users.update', membershipOf($guest)), ['name' => 'x', 'discount' => 50])->assertSessionHasErrors('discount');

    expect($guest->fresh())->name->toBe('Арман Сейтов')->email->toBe('new@example.com')
        ->and(ActivityLog::query()->where('event', 'user.updated')->exists())->toBeTrue();
});

test('staff accounts are not edited or reset from the customer list', function () {
    $owner = User::factory()->create();
    $cashier = guestWithRole('cashier');

    $this->actingAs($owner)->patch(route('users.update', membershipOf($cashier)), ['name' => 'x'])->assertForbidden();
    $this->post(route('users.password', membershipOf($cashier)))->assertForbidden();
});

test('without Telegram the new password is shown once and the customer is signed out', function () {
    $cashier = guestWithRole('cashier');
    $guest = guestWithRole('client', ['password' => bcrypt('old-secret')]);
    $guest->createToken('PC');

    $response = $this->actingAs($cashier)->post(route('users.password', membershipOf($guest)))->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertSee('Передайте клиенту новый пароль');
    preg_match('/tracking-widest[^>]*>([A-Za-z0-9]{8})</', $response->getContent(), $match);

    expect($match[1] ?? null)->not->toBeNull()
        ->and(Hash::check($match[1], $guest->fresh()->password))->toBeTrue()
        ->and($guest->tokens()->count())->toBe(0)
        ->and(ActivityLog::query()->where('event', 'user.password_reset')->sole()->details)->toBe(['delivery' => 'screen']);
    expect(session()->all())->not->toContain($match[1]);
});

test('with Telegram linked the new password goes to Telegram and is not shown', function () {
    withBot();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
    $cashier = guestWithRole('cashier');
    $guest = guestWithRole('client');
    $guest->forceFill(['telegram_chat_id' => '555'])->save();

    $this->actingAs($cashier)->post(route('users.password', membershipOf($guest)))->assertOk()
        ->assertSee('отправлен клиенту в Telegram')->assertDontSee('tracking-widest', false);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage') && $request['chat_id'] === '555' && str_contains($request['text'], 'Новый пароль'));
});

test('a customer links Telegram from the agent with a one-time link the bot confirms', function () {
    withBot();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
    $guest = guestWithRole('client', ['name' => 'Арман']);
    $clubId = membershipOf($guest)->club_id;
    app(CurrentClub::class)->clear();
    $headers = ['Authorization' => 'Bearer '.$guest->createToken('PC', ['club:'.$clubId])->plainTextToken, 'Accept' => 'application/json'];

    $this->getJson(route('api.guest.telegram'), $headers)->assertOk()->assertJsonPath('available', true)->assertJsonPath('linked', false);
    $url = $this->postJson(route('api.guest.telegram.link'), [], $headers)->assertOk()->json('url');
    expect($url)->toStartWith('https://t.me/ZafiroBot?start=');
    $code = substr($url, strlen('https://t.me/ZafiroBot?start='));

    $secret = app(Telegram::class)->webhookSecret();
    $update = ['message' => ['chat' => ['id' => 777], 'text' => '/start '.$code]];
    $this->postJson(route('telegram.webhook'), $update)->assertForbidden();
    $this->postJson(route('telegram.webhook'), $update, ['X-Telegram-Bot-Api-Secret-Token' => $secret])->assertOk();
    expect($guest->fresh()->telegram_chat_id)->toBe('777');

    // The link works once.
    $this->postJson(route('telegram.webhook'), ['message' => ['chat' => ['id' => 888], 'text' => '/start '.$code]], ['X-Telegram-Bot-Api-Secret-Token' => $secret])->assertOk();
    expect(User::query()->where('telegram_chat_id', '888')->exists())->toBeFalse();

    $this->postJson(route('telegram.webhook'), ['message' => ['chat' => ['id' => 777], 'text' => '/stop']], ['X-Telegram-Bot-Api-Secret-Token' => $secret])->assertOk();
    expect($guest->fresh()->telegram_chat_id)->toBeNull();
});

test('the superadmin connects the bot: token checked, webhook set, token stored encrypted', function () {
    Http::fake([
        'api.telegram.org/*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'ZafiroBot']]),
        'api.telegram.org/*/setWebhook' => Http::response(['ok' => true, 'result' => true]),
    ]);
    $admin = User::factory()->create();
    $admin->forceFill(['is_superadmin' => true])->save();

    $this->actingAs($admin)->post(route('superadmin.telegram.update'), ['token' => BOT_TOKEN])->assertSessionHasNoErrors();
    $this->get(route('superadmin.telegram.show'))->assertOk()->assertSee('@ZafiroBot');

    $stored = PlatformSetting::query()->find(Telegram::SETTING)->getRawOriginal('value');
    expect($stored)->not->toContain(BOT_TOKEN);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/setWebhook') && $request['url'] === route('telegram.webhook') && $request['secret_token'] === app(Telegram::class)->webhookSecret());
});
