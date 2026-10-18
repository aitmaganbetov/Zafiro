<?php

use App\Models\PasswordRecoveryCode;
use App\Models\PlatformLog;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\TelegramGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const GATEWAY_TOKEN = 'AAGexampleGatewayToken_1234567890';

function withGateway(): void
{
    PlatformSetting::write(TelegramGateway::SETTING, ['token' => Crypt::encryptString(GATEWAY_TOKEN)], null);
}

/** Fakes Gateway and returns the codes it was asked to send. */
function fakeGateway(bool $sends = true): ArrayObject
{
    $codes = new ArrayObject;
    Http::fake(['gatewayapi.telegram.org/*' => function ($request) use ($codes, $sends) {
        $codes[] = $request['code'] ?? null;

        return Http::response($sends ? ['ok' => true, 'result' => ['request_id' => 'r1']] : ['ok' => false, 'error' => 'PHONE_NUMBER_INVALID']);
    }]);

    return $codes;
}

function recoveringUser(): User
{
    $user = User::factory()->create(['phone' => '+77001112233', 'password' => Hash::make('old-secret')]);
    $user->createToken('PC');

    return $user;
}

test('in the agent a code goes to Telegram and sets a new password; the account is signed out everywhere', function () {
    withGateway();
    $codes = fakeGateway();
    $user = recoveringUser();
    DB::table('sessions')->insert(['id' => 'open', 'user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'x', 'payload' => 'x', 'last_activity' => now()->timestamp]);

    $this->postJson(route('api.auth.password.code'), ['phone' => '8 700 111-22-33'])->assertOk()->assertJsonPath('code_minutes', 5);
    expect($codes)->toHaveCount(1)->and($codes[0])->toMatch('/^\d{6}$/');
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendVerificationMessage')
        && $request->hasHeader('Authorization', 'Bearer '.GATEWAY_TOKEN)
        && $request['phone_number'] === '+77001112233' && $request['ttl'] === 300);
    // Only the hash is kept.
    expect(PasswordRecoveryCode::query()->sole()->code_hash)->not->toBe($codes[0]);

    $this->postJson(route('api.auth.password.reset'), ['phone' => '+77001112233', 'code' => $codes[0], 'password' => 'new-secret', 'password_confirmation' => 'other'])
        ->assertUnprocessable()->assertJsonValidationErrors('password');
    $this->postJson(route('api.auth.password.reset'), ['phone' => '+77001112233', 'code' => $codes[0], 'password' => 'new-secret', 'password_confirmation' => 'new-secret'])
        ->assertOk();

    expect(Hash::check('new-secret', $user->fresh()->password))->toBeTrue()
        ->and($user->tokens()->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(PasswordRecoveryCode::query()->exists())->toBeFalse()
        ->and(PlatformLog::query()->pluck('event')->all())->toContain('user.password_code_sent', 'user.password_recovered');

    // The code works once.
    $this->postJson(route('api.auth.password.reset'), ['phone' => '+77001112233', 'code' => $codes[0], 'password' => 'again-secret', 'password_confirmation' => 'again-secret'])
        ->assertUnprocessable()->assertJsonValidationErrors('code');
});

test('on the site: phone, then code and new password, then sign-in', function () {
    withGateway();
    $codes = fakeGateway();
    recoveringUser();

    $this->get(route('auth.show-login'))->assertOk()->assertSee(route('auth.password.forgot'));
    $this->get(route('auth.password.reset'))->assertRedirect(route('auth.password.forgot'));
    $this->post(route('auth.password.send'), ['phone' => '700 111-22-33'])->assertRedirect(route('auth.password.reset'));
    $this->get(route('auth.password.reset'))->assertOk()->assertSee('+77001112233');

    $this->post(route('auth.password.update'), ['code' => '000000', 'password' => 'new-secret', 'password_confirmation' => 'new-secret'])
        ->assertSessionHasErrors('code');
    $this->post(route('auth.password.update'), ['code' => $codes[0], 'password' => 'new-secret', 'password_confirmation' => 'new-secret'])
        ->assertRedirect(route('auth.show-login'));
    $this->post(route('auth.login'), ['phone' => '+77001112233', 'password' => 'new-secret']);
    $this->assertAuthenticated();
});

test('an unknown number gets the same answer and costs nothing', function () {
    withGateway();
    $codes = fakeGateway();

    $this->postJson(route('api.auth.password.code'), ['phone' => '+77009998877'])->assertOk();

    expect($codes)->toHaveCount(0)->and(PasswordRecoveryCode::query()->exists())->toBeFalse();
});

test('codes are limited: one a minute, five an hour, five tries each, five minutes long', function () {
    withGateway();
    $codes = fakeGateway();
    $user = recoveringUser();
    $send = fn () => $this->postJson(route('api.auth.password.code'), ['phone' => '+77001112233']);
    $reset = fn (string $code) => $this->postJson(route('api.auth.password.reset'), ['phone' => '+77001112233', 'code' => $code, 'password' => 'new-secret', 'password_confirmation' => 'new-secret']);

    $send()->assertOk();
    $send()->assertUnprocessable()->assertJsonValidationErrors('phone');

    // Five wrong tries spend the code, even the right one does not work then.
    foreach (range(1, 5) as $try) {
        $reset('000000')->assertUnprocessable();
    }
    $reset($codes[0])->assertUnprocessable()->assertJsonValidationErrors('code');

    // An expired code does not work.
    $this->travel(61)->seconds();
    $send()->assertOk();
    $this->travel(6)->minutes();
    $reset($codes[1])->assertUnprocessable();

    foreach (range(1, 3) as $more) {
        $this->travel(61)->seconds();
        $send()->assertOk();
    }
    $this->travel(61)->seconds();
    $send()->assertUnprocessable()->assertJsonValidationErrors('phone');
    expect($codes)->toHaveCount(5)->and(Hash::check('old-secret', $user->fresh()->password))->toBeTrue();
});

test('without Gateway, or when Telegram does not take the number, the customer is told; blocked accounts get nothing', function () {
    recoveringUser();
    $this->postJson(route('api.auth.password.code'), ['phone' => '+77001112233'])->assertUnprocessable()->assertJsonValidationErrors('phone');
    $this->get(route('auth.password.forgot'))->assertOk()->assertSee(__('Восстановление пароля через Telegram пока не подключено. Обратитесь к администратору клуба.'));

    withGateway();
    fakeGateway(sends: false);
    $this->postJson(route('api.auth.password.code'), ['phone' => '+77001112233'])->assertUnprocessable()->assertJsonValidationErrors('phone');
    expect(PasswordRecoveryCode::query()->exists())->toBeFalse();

    $codes = fakeGateway();
    $blocked = User::factory()->create(['phone' => '+77005554433']);
    $blocked->forceFill(['blocked_at' => now()])->save();
    $this->postJson(route('api.auth.password.code'), ['phone' => '+77005554433'])->assertOk();
    expect($codes)->toHaveCount(0);
});

test('the superadmin connects Gateway: the token is checked and stored encrypted', function () {
    $admin = User::factory()->superadmin()->create();
    Http::fake(['gatewayapi.telegram.org/*' => Http::sequence()
        ->push(['ok' => false, 'error' => 'ACCESS_TOKEN_INVALID'])
        ->push(['ok' => false, 'error' => 'REQUEST_ID_INVALID'])]);

    $this->actingAs($admin)->post(route('superadmin.telegram.update'), ['token' => GATEWAY_TOKEN])->assertSessionHasErrors('token');
    expect(app(TelegramGateway::class)->configured())->toBeFalse();

    $this->post(route('superadmin.telegram.update'), ['token' => GATEWAY_TOKEN])->assertSessionHasNoErrors();
    $this->get(route('superadmin.telegram.show'))->assertOk()->assertSee(__('Telegram Gateway подключён'));
    expect(PlatformSetting::query()->find(TelegramGateway::SETTING)->getRawOriginal('value'))->not->toContain(GATEWAY_TOKEN)
        ->and(app(TelegramGateway::class)->token())->toBe(GATEWAY_TOKEN);

    $this->delete(route('superadmin.telegram.destroy'))->assertSessionHasNoErrors();
    expect(app(TelegramGateway::class)->configured())->toBeFalse();
});

test('old agents are told Telegram in the profile is not available', function () {
    $user = User::factory()->create();
    $token = $user->createToken('PC', ['club:'.$user->memberships()->value('club_id')])->plainTextToken;

    $this->withToken($token)->getJson(route('api.guest.telegram'))->assertOk()->assertJson(['available' => false, 'linked' => false]);
});
