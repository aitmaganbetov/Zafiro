<?php

use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\CurrentClub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

test('the user list shows the club customers with their balances, not the staff', function () {
    $admin = User::factory()->create();
    User::factory()->create([
        'name' => 'Иван Петров',
        'phone' => '+79991234567',
    ])->currentMembership()->firstOrFail()->update(['role' => 'Гость', 'discount' => 12.5]);
    $guest = User::query()->where('phone', '+79991234567')->firstOrFail();
    $guest->currentMembership()->firstOrFail()->balance()->updateOrCreate(
        ['club_id' => app(CurrentClub::class)->id(), 'user_id' => $guest->id],
        ['balance' => 1250.5, 'bonus_balance' => 75],
    );

    $this->actingAs($admin)
        ->get(route('users.index'))
        ->assertSuccessful()
        ->assertSee('Иван Петров')
        ->assertSee('+79991234567')
        ->assertSee('12.50%')
        ->assertSee('1 250.50')
        ->assertSee('75.00');
});

test('guest users are redirected away from the user list', function () {
    $this->get(route('users.index'))->assertRedirect(route('auth.show-login'));
});

test('cashiers can open the cash register but cannot change club settings', function () {
    $cashier = User::factory()->create();
    $cashier->currentMembership()->firstOrFail()->update(['role' => 'cashier']);

    $this->actingAs($cashier)->get(route('cash-register.index'))->assertSuccessful();
    $this->actingAs($cashier)->get(route('settings.club'))->assertForbidden();
});

test('the user list creates customers only', function () {
    $admin = User::factory()->create();

    $this->actingAs($admin)->get(route('users.index'))
        ->assertSuccessful()
        ->assertSee('Добавить клиента')
        ->assertSee('<dialog id="user-create-dialog"', false)
        ->assertSee('>+7</span>', false)
        ->assertDontSee('<option value="cashier"', false);

    $this->post(route('users.store'), [
        'name' => 'Айжан Садыкова',
        'phone' => '7001234567',
        'email' => 'aizhan@example.com',
        'password' => 'guest-secret',
        'password_confirmation' => 'guest-secret',
        'discount' => 10.5,
        'balance' => 2500,
        'bonus_balance' => 150,
    ])->assertRedirect(route('users.index'))
        ->assertSessionHas('status', 'Пользователь добавлен.');

    $createdUser = User::query()->where('phone', '+77001234567')->firstOrFail();
    $membership = $createdUser->currentMembership()->firstOrFail();
    expect($createdUser->name)->toBe('Айжан Садыкова')
        ->and($membership->role)->toBe('client')
        ->and($membership->discount)->toBe('10.50')
        ->and($membership->balance->balance)->toBe('2500.00')
        ->and($membership->balance->bonus_balance)->toBe('150.00')
        ->and(Hash::check('guest-secret', $createdUser->password))->toBeTrue();

    expect(ActivityLog::query()->where('event', 'user.created')->where('user_id', $admin->id)->exists())->toBeTrue();
});

test('user creation validates unique phone and password confirmation', function () {
    $admin = User::factory()->create();
    User::factory()->create(['phone' => '+77001234567']);

    $this->actingAs($admin)->post(route('users.store'), [
        'name' => 'Повторный пользователь',
        'phone' => '7001234567',
        'password' => 'guest-secret',
        'password_confirmation' => 'different-secret',
    ])->assertSessionHasErrors(['phone', 'password']);

    expect(User::query()->where('name', 'Повторный пользователь')->exists())->toBeFalse();
});

test('guest users cannot create accounts through the admin form', function () {
    $this->post(route('users.store'), [])->assertRedirect(route('auth.show-login'));
});

test('non-staff members can still log out', function () {
    $member = User::factory()->create();
    $member->currentMembership()->firstOrFail()->update(['role' => 'Пользователь']);

    $this->actingAs($member)->post(route('auth.logout'))->assertRedirect('/');
});

test('cashiers and operators register customers without a discount or balance', function (string $role) {
    $staff = User::factory()->create();
    $staff->currentMembership()->firstOrFail()->update(['role' => $role]);

    $this->actingAs($staff)->get(route('users.index'))->assertSuccessful()
        ->assertSee('Добавить клиента')
        ->assertDontSee('id="new-user-discount"', false)
        ->assertDontSee('id="new-user-balance"', false);

    $form = ['name' => 'Гость', 'phone' => '7005556677', 'password' => 'guest-secret', 'password_confirmation' => 'guest-secret'];
    $this->post(route('users.store'), [...$form, 'balance' => 5000])->assertForbidden();
    expect(User::query()->where('phone', '+77005556677')->exists())->toBeFalse();

    $this->post(route('users.store'), $form)->assertRedirect(route('users.index'));
    $membership = User::query()->where('phone', '+77005556677')->firstOrFail()->currentMembership()->firstOrFail();
    expect($membership->role)->toBe('client')
        ->and($membership->discount)->toBe('0.00')
        ->and($membership->balance->balance)->toBe('0.00');
})->with(['cashier', 'operator']);

test('cashiers and operators do not see the admin menu and land on their own page', function (string $role, string $landing) {
    $staff = User::factory()->create();
    $staff->currentMembership()->firstOrFail()->update(['role' => $role]);

    $this->actingAs($staff)->get(route('dashboard'))->assertRedirect(route($landing));
    $page = $this->get(route($landing))->assertSuccessful();
    foreach (['settings', 'tariffs.index', 'news.index', 'billing.show', 'activity-logs.index'] as $hidden) {
        $page->assertDontSee('href="'.route($hidden).'"', false);
    }
    $page->assertDontSee('href="'.route('dashboard').'"', false)->assertSee('href="'.route('chat.index').'"', false);
    $this->get(route('billing.show'))->assertForbidden();
})->with([['cashier', 'cash-register.index'], ['operator', 'club-computers.index']]);

test('cashiers and operators open the club map without the editor, the PC list and the stock', function (string $role) {
    $staff = User::factory()->create();
    $staff->currentMembership()->firstOrFail()->update(['role' => $role]);
    Product::factory()->create(['name' => 'Coca Cola', 'quantity' => 7]);

    $this->actingAs($staff);
    $this->get(route('club-map.index'))->assertSuccessful()->assertSee('class="cm-editor-actions"  hidden', false);
    $this->get(route('club-computers.index'))->assertSuccessful();
    $this->get(route('stock.index'))->assertSuccessful()->assertSee('Coca Cola')->assertSee('7');
    $this->get(route('club-computers.index'))->assertSee('href="'.route('stock.index').'"', false);
    $this->get(route('warehouse'))->assertForbidden();
    $this->post(route('club-map.store'), ['name' => 'x'])->assertForbidden();
})->with(['cashier', 'operator']);

test('cashiers and operators receive goods into stock; write-offs stay with managers', function (string $role) {
    $staff = User::factory()->create();
    $staff->currentMembership()->firstOrFail()->update(['role' => $role]);
    $product = Product::factory()->create(['name' => 'Coca Cola', 'quantity' => 3]);

    $this->actingAs($staff)->get(route('stock.index'))->assertSee('Оприходовать');
    $this->post(route('stock.receive'), ['product_id' => $product->id, 'quantity' => 0])->assertSessionHasErrors('quantity');
    $this->post(route('stock.receive'), ['product_id' => $product->id, 'quantity' => 12, 'note' => 'Поставка'])->assertSessionHasNoErrors();

    expect($product->fresh()->quantity)->toBe(15)
        ->and(StockMovement::query()->latest('id')->first())
        ->type->toBe(StockMovement::TYPE_RECEIPT)
        ->user_id->toBe($staff->id);
    $this->post(route('warehouse.stock-movements.store'), ['product_id' => $product->id, 'type' => 'write_off', 'quantity' => 1])->assertForbidden();
})->with(['cashier', 'operator']);
