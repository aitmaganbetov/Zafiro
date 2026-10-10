<?php

use App\Models\User;
use App\Models\ActivityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

test('authenticated users can view the user list and account details', function () {
    $admin = User::factory()->create();
    User::factory()->create([
        'name' => 'Иван Петров',
        'phone' => '+79991234567',
        'role' => 'Гость',
        'discount' => 12.5,
        'balance' => 1250.5,
        'bonus_balance' => 75,
    ]);

    $this->actingAs($admin)
        ->get(route('users.index'))
        ->assertSuccessful()
        ->assertSee('Иван Петров')
        ->assertSee('+79991234567')
        ->assertSee('Гость')
        ->assertSee('12.50%')
        ->assertSee('1 250.50')
        ->assertSee('75.00');
});

test('guest users are redirected away from the user list', function () {
    $this->get(route('users.index'))->assertRedirect(route('auth.show-login'));
});

test('authenticated users can create an account from the user list', function () {
    $admin = User::factory()->create();

    $this->actingAs($admin)->get(route('users.index'))
        ->assertSuccessful()
        ->assertSee('Добавить пользователя')
        ->assertSee('<dialog id="user-create-dialog"', false)
        ->assertSee('>+7</span>', false)
        ->assertSee('<option value="admin"', false)
        ->assertSee('<option value="cashier"', false)
        ->assertSee('<option value="manager"', false)
        ->assertSee('<option value="operator"', false);

    $this->post(route('users.store'), [
        'name' => 'Айжан Садыкова',
        'phone' => '7001234567',
        'email' => 'aizhan@example.com',
        'role' => 'cashier',
        'password' => 'guest-secret',
        'password_confirmation' => 'guest-secret',
        'discount' => 10.5,
        'balance' => 2500,
        'bonus_balance' => 150,
    ])->assertRedirect(route('users.index'))
        ->assertSessionHas('status', 'Пользователь добавлен.');

    $createdUser = User::query()->where('phone', '+77001234567')->firstOrFail();
    expect($createdUser->name)->toBe('Айжан Садыкова')
        ->and($createdUser->role)->toBe('cashier')
        ->and($createdUser->discount)->toBe('10.50')
        ->and($createdUser->balance)->toBe('2500.00')
        ->and($createdUser->bonus_balance)->toBe('150.00')
        ->and(Hash::check('guest-secret', $createdUser->password))->toBeTrue();

    expect(ActivityLog::query()->where('event', 'user.created')->where('user_id', $admin->id)->exists())->toBeTrue();
});

test('user creation validates unique phone and password confirmation', function () {
    $admin = User::factory()->create();
    User::factory()->create(['phone' => '+77001234567']);

    $this->actingAs($admin)->post(route('users.store'), [
        'name' => 'Повторный пользователь',
        'phone' => '7001234567',
        'role' => 'invalid-role',
        'password' => 'guest-secret',
        'password_confirmation' => 'different-secret',
    ])->assertSessionHasErrors(['phone', 'password', 'role']);

    expect(User::query()->where('name', 'Повторный пользователь')->exists())->toBeFalse();
});

test('guest users cannot create accounts through the admin form', function () {
    $this->post(route('users.store'), [])->assertRedirect(route('auth.show-login'));
});