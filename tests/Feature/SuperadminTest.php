<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('superadmin rights are granted and revoked only from the console', function () {
    $user = User::factory()->create(['phone' => '+77001234567']);

    expect($user->isSuperadmin())->toBeFalse();

    $this->artisan('superadmin:grant', ['phone' => '8 (700) 123-45-67'])->assertSuccessful();
    expect($user->refresh()->isSuperadmin())->toBeTrue();

    $this->artisan('superadmin:grant', ['phone' => '+77001234567', '--revoke' => true])->assertSuccessful();
    expect($user->refresh()->isSuperadmin())->toBeFalse();

    $this->artisan('superadmin:grant', ['phone' => '+77009999999'])->assertFailed();
});

test('the superadmin flag cannot be set through registration or user forms', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)->post(route('users.store'), [
        'name' => 'Кассир',
        'phone' => '7001112233',
        'role' => 'cashier',
        'password' => 'secret123',
        'password_confirmation' => 'secret123',
        'is_superadmin' => 1,
    ]);
    auth()->logout();
    $this->post(route('auth.register'), [
        'name' => 'Новый',
        'club_name' => 'Клуб',
        'phone' => '7002223344',
        'password' => 'secret123',
        'password_confirmation' => 'secret123',
        'is_superadmin' => 1,
    ]);

    expect(User::query()->where('is_superadmin', true)->exists())->toBeFalse();
});
