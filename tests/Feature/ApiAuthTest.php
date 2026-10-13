<?php

use App\Models\User;
use App\Models\MemberBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

test('mobile users can login, inspect their profile, and revoke their token', function () {
    $user = User::factory()->create([
        'name' => 'Мобильный пользователь',
        'phone' => '+79991234567',
        'password' => Hash::make('secret-password'),
    ]);
    $user->currentMembership()->firstOrFail()->update(['role' => 'Гость', 'discount' => 5]);
    MemberBalance::query()->where('user_id', $user->id)->update(['balance' => 1200, 'bonus_balance' => 30]);

    $loginResponse = $this->postJson('/api/auth/login', [
        'phone' => '+79991234567',
        'password' => 'secret-password',
        'device_name' => 'ios-app',
    ]);

    $loginResponse
        ->assertSuccessful()
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('user.phone', '+79991234567')
        ->assertJsonPath('user.role', 'Гость')
        ->assertJsonPath('user.balance', 1200);

    $token = $loginResponse->json('token');

    $this->withToken($token)
        ->getJson('/api/auth/me')
        ->assertSuccessful()
        ->assertJsonPath('user.name', 'Мобильный пользователь');

    $this->withToken($token)->postJson('/api/auth/logout')->assertSuccessful();
    $this->assertDatabaseMissing('personal_access_tokens', [
        'id' => (int) explode('|', $token, 2)[0],
    ]);

    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
});

test('mobile login rejects invalid credentials', function () {
    User::factory()->create([
        'phone' => '+79991234567',
        'password' => Hash::make('secret-password'),
    ]);

    $this->postJson('/api/auth/login', [
        'phone' => '+79991234567',
        'password' => 'wrong-password',
    ])->assertUnauthorized();
});

test('mobile profile requires a bearer token', function () {
    $this->getJson('/api/auth/me')->assertUnauthorized();
});