<?php

use App\Models\ActivityLog;
use App\Models\ClubMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

function profileCustomer(): array
{
    User::factory()->create(); // the club owner
    $customer = User::factory()->create(['name' => 'Арман', 'password' => 'old-secret']);
    $membership = ClubMembership::query()->where('user_id', $customer->id)->sole();
    $membership->update(['role' => 'client']);
    $token = $customer->createToken('PC-01', ['club:'.$membership->club_id])->plainTextToken;

    return [$customer, ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json']];
}

test('a customer changes their name in the agent profile', function () {
    [$customer, $headers] = profileCustomer();

    $this->postJson(route('api.guest.profile.name'), ['name' => '  Арман Сейтов '], $headers)->assertOk()
        ->assertJson(['name' => 'Арман Сейтов', 'message' => 'Имя сохранено.']);
    expect($customer->fresh()->name)->toBe('Арман Сейтов')
        ->and(ActivityLog::query()->where('event', 'user.profile_updated')->sole()->details)->toBe(['name' => 'Арман → Арман Сейтов']);

    $this->postJson(route('api.guest.profile.name'), ['name' => ' '], $headers)->assertUnprocessable()->assertJsonValidationErrors('name');
});

test('a customer changes the password with the current one; other devices sign in again', function () {
    [$customer, $headers] = profileCustomer();
    $phone = $customer->createToken('mobile')->plainTextToken;

    $this->postJson(route('api.guest.profile.password'), ['current_password' => 'wrong', 'password' => 'new-secret', 'password_confirmation' => 'new-secret'], $headers)
        ->assertUnprocessable()->assertJsonValidationErrors('current_password');
    $this->postJson(route('api.guest.profile.password'), ['current_password' => 'old-secret', 'password' => 'new-secret', 'password_confirmation' => 'other'], $headers)
        ->assertUnprocessable()->assertJsonValidationErrors('password');
    $this->postJson(route('api.guest.profile.password'), ['current_password' => 'old-secret', 'password' => '123', 'password_confirmation' => '123'], $headers)
        ->assertUnprocessable()->assertJsonValidationErrors('password');

    $this->postJson(route('api.guest.profile.password'), ['current_password' => 'old-secret', 'password' => 'new-secret', 'password_confirmation' => 'new-secret'], $headers)
        ->assertOk()->assertJsonPath('message', 'Пароль изменён. На других устройствах войдите с новым паролем.');

    expect(Hash::check('new-secret', $customer->fresh()->password))->toBeTrue()
        ->and($customer->tokens()->pluck('name')->all())->toBe(['PC-01'])
        ->and(json_encode(ActivityLog::query()->where('event', 'user.password_changed')->sole()->toArray()))->not->toContain('new-secret');

    app('auth')->forgetGuards();
    $this->getJson(route('api.guest.history'), ['Authorization' => 'Bearer '.$phone, 'Accept' => 'application/json'])->assertUnauthorized();
});
