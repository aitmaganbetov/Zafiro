<?php

use App\Models\Club;
use App\Models\ClubComputer;
use App\Models\ClubSubscription;
use App\Models\ClubZone;
use App\Models\PlatformSetting;
use App\Models\Reseller;
use App\Models\SaasPlan;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Services\CurrentClub;
use App\Services\SubscriptionBilling;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** A reseller with their percent and discount, as the superadmin set them. */
function makeReseller(string $code = 'arman-games', float $percent = 10, int $discount = 15, bool $active = true): Reseller
{
    $reseller = new Reseller(['code' => $code, 'name' => 'Arman']);
    $reseller->forceFill(['user_id' => User::factory()->create()->id, 'commission_percent' => $percent, 'discount_percent' => $discount, 'is_active' => $active])->save();

    return $reseller;
}

test('a reseller signs up on the reseller host with a latin code of their own', function () {
    makeReseller('taken');

    $this->get(route('reseller.register'))->assertOk()->assertSee('Код реселлера');

    $this->post(route('reseller.register.store'), ['name' => 'Arman', 'code' => 'taken', 'phone' => '7001234567', 'password' => 'secret12', 'password_confirmation' => 'secret12'])
        ->assertSessionHasErrors('code');
    $this->post(route('reseller.register.store'), ['name' => 'Arman', 'code' => 'Арман', 'phone' => '7001234567', 'password' => 'secret12', 'password_confirmation' => 'secret12'])
        ->assertSessionHasErrors('code');

    $this->post(route('reseller.register.store'), ['name' => 'Arman', 'code' => 'Arman-Games', 'phone' => '700 123-45-67', 'password' => 'secret12', 'password_confirmation' => 'secret12'])
        ->assertRedirect(route('reseller.dashboard'));

    $reseller = Reseller::query()->where('code', 'arman-games')->sole();
    expect($reseller->user->phone)->toBe('+77001234567')
        ->and((float) $reseller->commission_percent)->toBe(0.0)
        ->and($reseller->discount_percent)->toBe(0);
    $this->get(route('reseller.dashboard'))->assertOk()->assertSee('arman-games')->assertSee('Пока ни один клуб не ввёл ваш код.');
});

test('an account signs in on the reseller host and becomes a reseller; other pages there lead to the cabinet', function () {
    $user = User::factory()->create(['phone' => '+77005556677', 'password' => 'secret12']);
    $host = 'http://'.config('app.reseller_domain');

    $this->post($host.'/login', ['phone' => '+77005556677', 'password' => 'secret12'])->assertRedirect(route('reseller.dashboard'));
    $this->get(route('reseller.dashboard'))->assertOk()->assertSee('Стать реселлером');

    $this->post(route('reseller.store'), ['name' => 'Club Partner', 'code' => 'partner'])->assertRedirect(route('reseller.dashboard'));
    expect(Reseller::query()->where('user_id', $user->id)->value('code'))->toBe('partner');

    $this->get($host.'/dashboard')->assertRedirect(route('reseller.dashboard'));
    $this->get($host.'/register')->assertRedirect(route('reseller.register'));
});

test('a club enters the reseller code once; unknown or stopped codes are refused', function () {
    $owner = User::factory()->create();
    makeReseller('stopped', active: false);
    $reseller = makeReseller();
    $club = Club::query()->findOrFail(app(CurrentClub::class)->id());

    $this->actingAs($owner);
    $this->post(route('settings.reseller'), ['reseller_code' => 'nobody'])->assertSessionHasErrors('reseller_code');
    $this->post(route('settings.reseller'), ['reseller_code' => 'stopped'])->assertSessionHasErrors('reseller_code');

    $this->post(route('settings.reseller'), ['reseller_code' => ' Arman-Games '])->assertSessionHasNoErrors();
    expect($club->fresh()->reseller_id)->toBe($reseller->id);
    $this->get(route('settings.club'))->assertOk()->assertSee('Клуб подключён через реселлера Arman (код arman-games).', false);

    $this->post(route('settings.reseller'), ['reseller_code' => 'stopped'])->assertSessionHasErrors('reseller_code');
    expect($club->fresh()->reseller_id)->toBe($reseller->id);
});

test('a club of a reseller pays with the reseller discount and the reseller earns their percent', function () {
    $club = Club::query()->findOrFail(app(CurrentClub::class)->id());
    $zone = ClubZone::query()->create(['name' => 'Hall']);
    foreach ([1, 2] as $number) {
        ClubComputer::query()->create(['club_zone_id' => $zone->id, 'number' => $number]);
    }
    $plan = SaasPlan::query()->create(['slug' => 'base', 'name' => 'Базовый', 'price_monthly' => 1000, 'trial_days' => 0, 'is_active' => true]);
    PlatformSetting::write(SubscriptionBilling::KASPI_SETTING, ['url' => 'https://pay.kaspi.kz/pay/zafiro', 'recipient' => 'ИП Zafiro'], null);
    // The club's own discount is smaller: the reseller's applies.
    ClubSubscription::query()->where('club_id', $club->id)->update(['discount_percent' => 5]);
    $reseller = makeReseller(percent: 10, discount: 20);
    $club->forceFill(['reseller_id' => $reseller->id, 'reseller_attached_at' => now()])->save();
    $owner = User::factory()->create();
    $admin = User::factory()->superadmin()->create(['phone' => '+77009990001']);

    $this->actingAs($owner)->post(route('billing.invoices.store'), ['saas_plan_id' => $plan->id, 'months' => 3])->assertSessionHasNoErrors();
    $invoice = SubscriptionInvoice::query()->sole();
    // 1 000 ₸ × 2 PCs × 3 months − 20%
    expect((float) $invoice->amount)->toBe(4800.0)
        ->and($invoice->discount_percent)->toBe(20)
        ->and($invoice->reseller_id)->toBe($reseller->id);

    $this->actingAs($admin)->post(route('superadmin.invoices.pay', $invoice->id))->assertSessionHasNoErrors();
    expect((float) $invoice->fresh()->reseller_amount)->toBe(480.0);

    $this->post(route('superadmin.resellers.payout', $reseller), ['amount' => 300, 'note' => 'Kaspi'])->assertSessionHasNoErrors();
    $this->get(route('superadmin.resellers.index'))->assertOk()->assertSee('arman-games')->assertSee('к выплате 180 ₸');

    $this->actingAs($reseller->user)->get(route('reseller.dashboard'))->assertOk()
        ->assertSee('480 ₸')->assertSee('180 ₸')->assertSee($club->name);
});

test('the superadmin sets the percent and discount, stops a code and unlinks a club; others cannot', function () {
    $reseller = makeReseller(percent: 0, discount: 0);
    $club = Club::query()->findOrFail(app(CurrentClub::class)->id());
    $club->forceFill(['reseller_id' => $reseller->id])->save();
    $admin = User::factory()->superadmin()->create(['phone' => '+77009990001']);

    $this->actingAs(User::factory()->create())->patch(route('superadmin.resellers.update', $reseller), ['commission_percent' => 50, 'discount_percent' => 50, 'is_active' => 1])
        ->assertForbidden();

    $this->actingAs($admin)->patch(route('superadmin.resellers.update', $reseller), ['commission_percent' => 12.5, 'discount_percent' => 10, 'is_active' => 0])
        ->assertSessionHasNoErrors();
    $reseller->refresh();
    expect((float) $reseller->commission_percent)->toBe(12.5)
        ->and($reseller->discount_percent)->toBe(10)
        ->and($reseller->is_active)->toBeFalse();
    // A stopped code gives no discount.
    expect(app(SubscriptionBilling::class)->discountFor($club->fresh()))->toBe(0);

    $this->delete(route('superadmin.resellers.detach', [$reseller, $club]))->assertSessionHasNoErrors();
    expect($club->fresh()->reseller_id)->toBeNull();
});
