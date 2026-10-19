<?php

use App\Models\ActivityLog;
use App\Models\Club;
use App\Models\Friendship;
use App\Models\MemberBalance;
use App\Models\PlatformSetting;
use App\Models\SaasPlan;
use App\Models\TransferCode;
use App\Models\User;
use App\Services\CurrentClub;
use App\Services\TelegramGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

/** A second club with an active subscription, where the user is also a member. */
function secondClubOf(User $user, string $name = 'Второй клуб', bool $active = true): Club
{
    $first = app(CurrentClub::class)->id();
    $club = Club::query()->create(['name' => $name, 'slug' => 'mobile-'.uniqid()]);
    $club->subscription()->create([
        'saas_plan_id' => SaasPlan::query()->firstOrCreate(['slug' => 'trial'], ['name' => 'Пробный период', 'trial_days' => 14, 'is_active' => true])->id,
        'status' => $active ? 'active' : 'trialing',
        'started_at' => now()->subDay(),
        'trial_ends_at' => $active ? null : now()->subSecond(),
    ]);
    app(CurrentClub::class)->set($club);
    $user->memberships()->create(['role' => 'owner']);
    MemberBalance::query()->create(['user_id' => $user->id, 'balance' => 77, 'bonus_balance' => 0]);
    app(CurrentClub::class)->set(Club::query()->findOrFail($first));

    return $club;
}

/** The club's "Guests" settings: friends always on, transfers to friends as given. */
function transfersInClub(bool $on): void
{
    $club = Club::query()->findOrFail(app(CurrentClub::class)->id());
    $club->forceFill(['settings' => ['guests' => ['allow_adding_friends' => true, 'allow_balance_transfer_to_friends' => $on]]])->save();
    app(CurrentClub::class)->set($club);
}

/** Fakes Telegram Gateway and returns the codes it was asked to send. */
function fakeTransferGateway(): ArrayObject
{
    PlatformSetting::write(TelegramGateway::SETTING, ['token' => Crypt::encryptString('AAGexampleGatewayToken_1234567890')], null);
    $codes = new ArrayObject;
    Http::fake(['gatewayapi.telegram.org/*' => function ($request) use ($codes) {
        $codes[] = $request['code'] ?? null;

        return Http::response(['ok' => true, 'result' => ['request_id' => 'r1']]);
    }]);

    return $codes;
}

function mobileToken(User $user, ?int $clubId = null): array
{
    app('auth')->forgetGuards();
    $token = $user->createToken('mobileclient', ['club:'.($clubId ?? app(CurrentClub::class)->id())])->plainTextToken;

    return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
}

test('the app sign-in lists the clubs of the account; the PC agent contract stays as it was', function () {
    $user = User::factory()->create(['phone' => '+77001112233', 'password' => Hash::make('secret-pass')]);
    $firstId = app(CurrentClub::class)->id();
    $first = Club::query()->findOrFail($firstId);
    $first->update(['name' => 'Альфа']);
    $second = secondClubOf($user, 'Бета');
    app(CurrentClub::class)->clear();

    $this->postJson(route('api.auth.login'), ['phone' => '+77001112233', 'password' => 'secret-pass', 'device_name' => 'mobileclient', 'club_id' => $firstId])
        ->assertOk()
        ->assertJsonPath('club_id', $firstId)
        ->assertJsonPath('clubs', [
            ['id' => $firstId, 'name' => 'Альфа', 'current' => true],
            ['id' => $second->id, 'name' => 'Бета', 'current' => false],
        ]);

    $this->postJson(route('api.agent.user.login'), ['phone' => '+77001112233', 'password' => 'secret-pass', 'club_id' => $firstId])
        ->assertOk()->assertJsonMissingPath('clubs');
});

test('the app moves to another club of the account with a new token; the old one stops working', function () {
    $user = User::factory()->create();
    $firstId = app(CurrentClub::class)->id();
    $second = secondClubOf($user);
    $expired = secondClubOf($user, 'Без подписки', active: false);
    $stranger = Club::query()->create(['name' => 'Чужой', 'slug' => 'mobile-stranger']);
    $headers = mobileToken($user);
    app(CurrentClub::class)->clear();

    $this->getJson(route('api.auth.clubs'), $headers)->assertOk()->assertJsonCount(3, 'clubs')
        ->assertJsonFragment(['id' => $firstId, 'current' => true]);

    app('auth')->forgetGuards();
    $this->postJson(route('api.auth.switch-club'), ['club_id' => $stranger->id], $headers)->assertForbidden();
    app('auth')->forgetGuards();
    $this->postJson(route('api.auth.switch-club'), ['club_id' => $expired->id], $headers)->assertStatus(402);

    app('auth')->forgetGuards();
    $switched = $this->postJson(route('api.auth.switch-club'), ['club_id' => $second->id], $headers)->assertOk()
        ->assertJsonPath('club_id', $second->id)
        ->assertJsonPath('club_name', 'Второй клуб')
        ->assertJsonPath('user.balance', 77)
        ->assertJsonFragment(['id' => $second->id, 'current' => true]);
    expect($user->tokens()->sole()->abilities)->toBe(['club:'.$second->id])
        ->and($user->tokens()->sole()->name)->toBe('mobileclient')
        ->and(ActivityLog::query()->withoutGlobalScope('club')->where('event', 'auth.api_club_switched')->where('club_id', $second->id)->exists())->toBeTrue();

    app('auth')->forgetGuards();
    $this->getJson(route('api.auth.me'), $headers)->assertUnauthorized();
    app('auth')->forgetGuards();
    $this->getJson(route('api.auth.me'), ['Authorization' => 'Bearer '.$switched->json('access_token'), 'Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('user.club_id', $second->id);
});

test('a transfer from the app is confirmed with a code from Telegram, bound to the friend and the amount, once', function () {
    transfersInClub(true);
    $codes = fakeTransferGateway();
    $arman = User::factory()->create(['phone' => '+77011112233']);
    $dana = User::factory()->create(['phone' => '+77029998877']);
    MemberBalance::query()->where('user_id', $arman->id)->update(['balance' => 100]);
    MemberBalance::query()->where('user_id', $dana->id)->update(['balance' => 10]);
    $friendship = Friendship::query()->create(['requester_id' => $arman->id, 'addressee_id' => $dana->id, 'accepted_at' => now()]);
    $app = mobileToken($arman);

    // Nothing is sent (codes cost money) for a transfer that cannot go through.
    $this->postJson(route('api.guest.friends.transfer.code', $friendship), ['amount' => '500'], $app)->assertJsonValidationErrors('amount');
    expect($codes)->toHaveCount(0);

    app('auth')->forgetGuards();
    $this->postJson(route('api.guest.friends.transfer.code', $friendship), ['amount' => '40'], $app)->assertOk()->assertJsonPath('code_minutes', 5);
    expect($codes)->toHaveCount(1);
    $code = $codes[0];
    $wrong = $code === '111111' ? '222222' : '111111';

    app('auth')->forgetGuards();
    $this->postJson(route('api.guest.friends.transfer.code', $friendship), ['amount' => '40'], $app)->assertJsonValidationErrors('code');
    expect($codes)->toHaveCount(1);

    app('auth')->forgetGuards();
    $this->postJson(route('api.guest.friends.transfer.confirm', $friendship), ['amount' => '40', 'code' => $wrong], $app)->assertJsonValidationErrors('code');
    app('auth')->forgetGuards();
    $this->postJson(route('api.guest.friends.transfer.confirm', $friendship), ['amount' => '90', 'code' => $code], $app)->assertJsonValidationErrors('code');
    expect(TransferCode::query()->sole()->attempts)->toBe(2)
        ->and(MemberBalance::query()->where('user_id', $arman->id)->value('balance'))->toBe('100.00');

    app('auth')->forgetGuards();
    $this->postJson(route('api.guest.friends.transfer.confirm', $friendship), ['amount' => '40', 'code' => $code], $app)->assertOk()
        ->assertJsonPath('balance', '60.00');
    expect(MemberBalance::query()->where('user_id', $dana->id)->value('balance'))->toBe('50.00')
        ->and(TransferCode::query()->count())->toBe(0);

    app('auth')->forgetGuards();
    $this->postJson(route('api.guest.friends.transfer.confirm', $friendship), ['amount' => '40', 'code' => $code], $app)->assertJsonValidationErrors('code');
    expect(MemberBalance::query()->where('user_id', $arman->id)->value('balance'))->toBe('60.00');
});

test('transfer codes stop after five wrong attempts and are not sent when transfers are off', function () {
    transfersInClub(true);
    $codes = fakeTransferGateway();
    $arman = User::factory()->create(['phone' => '+77011112233']);
    $dana = User::factory()->create();
    MemberBalance::query()->where('user_id', $arman->id)->update(['balance' => 100]);
    $friendship = Friendship::query()->create(['requester_id' => $arman->id, 'addressee_id' => $dana->id, 'accepted_at' => now()]);
    $app = mobileToken($arman);

    $this->postJson(route('api.guest.friends.transfer.code', $friendship), ['amount' => '10'], $app)->assertOk();
    $wrong = $codes[0] === '111111' ? '222222' : '111111';
    foreach (range(1, 5) as $attempt) {
        app('auth')->forgetGuards();
        $this->postJson(route('api.guest.friends.transfer.confirm', $friendship), ['amount' => '10', 'code' => $wrong], $app)->assertJsonValidationErrors('code');
    }
    app('auth')->forgetGuards();
    $this->postJson(route('api.guest.friends.transfer.confirm', $friendship), ['amount' => '10', 'code' => $codes[0]], $app)->assertJsonValidationErrors('code');
    expect(MemberBalance::query()->where('user_id', $arman->id)->value('balance'))->toBe('100.00');

    transfersInClub(false);
    app('auth')->forgetGuards();
    $this->postJson(route('api.guest.friends.transfer.code', $friendship), ['amount' => '10'], $app)->assertForbidden();
    expect($codes)->toHaveCount(1);
});
