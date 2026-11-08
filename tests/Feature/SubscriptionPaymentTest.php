<?php

use App\Models\Club;
use App\Models\ClubComputer;
use App\Models\ClubMembership;
use App\Models\ClubSubscription;
use App\Models\ClubZone;
use App\Models\PlatformLog;
use App\Models\PlatformSetting;
use App\Models\SaasPlan;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Services\CurrentClub;
use App\Services\Monitoring\TelegramAlerts;
use App\Services\SubscriptionBilling;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/** The test club with N PCs, a paid plan for 1 000 ₸ per PC and the Kaspi link set up. */
function billingClub(int $computers = 3): array
{
    $club = Club::query()->findOrFail(app(CurrentClub::class)->id());
    $zone = ClubZone::query()->create(['name' => 'Hall']);
    foreach (range(1, $computers) as $number) {
        ClubComputer::query()->create(['club_zone_id' => $zone->id, 'number' => $number]);
    }
    $plan = SaasPlan::query()->create(['slug' => 'base', 'name' => 'Базовый', 'price_monthly' => 1000, 'trial_days' => 0, 'is_active' => true]);
    PlatformSetting::write(SubscriptionBilling::KASPI_SETTING, ['url' => 'https://pay.kaspi.kz/pay/zafiro', 'recipient' => 'ИП Zafiro'], null);

    return [$club, $plan, User::factory()->create()];
}

test('the owner opens a bill for all the club PCs, less the discount, and sees how to pay it', function () {
    [$club, $plan, $owner] = billingClub(3);
    ClubSubscription::query()->where('club_id', $club->id)->update(['discount_percent' => 10]);

    $this->actingAs($owner)->get(route('billing.show'))->assertOk()
        ->assertSee('Продлить подписку')->assertSee('ПК в клубе: 3');

    $this->post(route('billing.invoices.store'), ['saas_plan_id' => $plan->id, 'months' => 3])->assertRedirect(route('billing.show'));

    $invoice = SubscriptionInvoice::query()->sole();
    // 1 000 ₸ × 3 PCs × 3 months − 10%
    expect((float) $invoice->amount)->toBe(8100.0)
        ->and($invoice->computers)->toBe(3)->and($invoice->discount_percent)->toBe(10)->and($invoice->status)->toBe('pending');

    $this->get(route('billing.show'))->assertOk()
        ->assertSee($invoice->number())->assertSee('8 100 ₸')
        ->assertSee('https://pay.kaspi.kz/pay/zafiro', false)->assertSee('ИП Zafiro')
        ->assertDontSee('Выставить счёт');

    $this->post(route('billing.invoices.store'), ['saas_plan_id' => $plan->id, 'months' => 1])->assertSessionHasErrors('invoice');
    expect(SubscriptionInvoice::query()->count())->toBe(1);

    $this->post(route('billing.invoices.cancel', $invoice))->assertRedirect(route('billing.show'));
    expect($invoice->fresh()->status)->toBe('canceled');
});

test('only allowed months, payable plans and a configured Kaspi link make a bill', function () {
    [$club, $plan, $owner] = billingClub();
    $trial = SaasPlan::query()->firstOrCreate(['slug' => 'trial'], ['name' => 'Пробный период', 'trial_days' => 14, 'is_active' => true]);

    $this->actingAs($owner);
    $this->post(route('billing.invoices.store'), ['saas_plan_id' => $plan->id, 'months' => 2])->assertSessionHasErrors('months');
    $this->post(route('billing.invoices.store'), ['saas_plan_id' => $trial->id, 'months' => 1])->assertSessionHasErrors('saas_plan_id');

    PlatformSetting::write(SubscriptionBilling::KASPI_SETTING, null, null);
    $this->post(route('billing.invoices.store'), ['saas_plan_id' => $plan->id, 'months' => 1])->assertSessionHasErrors('invoice');
    expect(SubscriptionInvoice::query()->count())->toBe(0);
});

test('staff without the pay right cannot open or cancel bills, and other clubs bills are out of reach', function () {
    [$club, $plan, $owner] = billingClub();
    $manager = User::factory()->create();
    ClubMembership::query()->where('user_id', $manager->id)->update(['role' => 'manager']);

    $this->actingAs($manager)->get(route('billing.show'))->assertOk()->assertSee('Продлить подписку может владелец клуба');
    $this->post(route('billing.invoices.store'), ['saas_plan_id' => $plan->id, 'months' => 1])->assertForbidden();

    $other = Club::query()->create(['name' => 'Other', 'slug' => 'other-club']);
    $foreign = SubscriptionInvoice::query()->withoutGlobalScope('club')->forceCreate(['club_id' => $other->id, 'saas_plan_id' => $plan->id, 'months' => 1, 'computers' => 1, 'price_per_computer' => 1000, 'amount' => 1000]);
    $this->actingAs($owner)->post(route('billing.invoices.cancel', $foreign))->assertNotFound();
    expect($foreign->fresh()->status)->toBe('pending');
});

test('an expired club can still pay', function () {
    [$club, $plan, $owner] = billingClub();
    ClubSubscription::query()->where('club_id', $club->id)->update(['status' => 'trialing', 'trial_ends_at' => now()->subDay()]);

    $this->actingAs($owner)->get(route('dashboard'))->assertRedirect(route('billing.show'));
    $this->post(route('billing.invoices.store'), ['saas_plan_id' => $plan->id, 'months' => 1])->assertRedirect(route('billing.show'));
    expect(SubscriptionInvoice::query()->count())->toBe(1);
});

test('the superadmin marks a bill paid and the subscription runs on from its current end', function () {
    [$club, $plan, $owner] = billingClub(2);
    $end = now()->addDays(10)->startOfMinute();
    ClubSubscription::query()->where('club_id', $club->id)->update(['status' => 'active', 'current_period_ends_at' => $end]);
    $invoice = SubscriptionInvoice::query()->create(['saas_plan_id' => $plan->id, 'months' => 6, 'computers' => 2, 'price_per_computer' => 1000, 'amount' => 12000, 'created_by' => $owner->id]);
    $admin = User::factory()->superadmin()->create(['phone' => '+77009990001']);

    $this->actingAs($admin)->get(route('superadmin.invoices.index'))->assertOk()->assertSee($invoice->number())->assertSee('12 000 ₸');
    $this->post(route('superadmin.invoices.pay', $invoice->id))->assertSessionHasNoErrors();

    $subscription = ClubSubscription::query()->where('club_id', $club->id)->sole();
    expect($subscription->status)->toBe('active')
        ->and($subscription->saas_plan_id)->toBe($plan->id)
        ->and($subscription->current_period_ends_at->equalTo($end->copy()->addMonthsNoOverflow(6)))->toBeTrue()
        ->and($invoice->fresh()->status)->toBe('paid')
        ->and($invoice->fresh()->confirmed_by)->toBe($admin->id)
        ->and(PlatformLog::query()->where('event', 'superadmin.invoice_paid')->count())->toBe(1);

    $this->post(route('superadmin.invoices.pay', $invoice->id))->assertSessionHasErrors('invoice');
    expect(ClubSubscription::query()->where('club_id', $club->id)->sole()->current_period_ends_at->equalTo($end->copy()->addMonthsNoOverflow(6)))->toBeTrue();
});

test('a paid bill of an expired club counts from today', function () {
    [$club, $plan] = billingClub(1);
    ClubSubscription::query()->where('club_id', $club->id)->update(['status' => 'expired', 'current_period_ends_at' => now()->subMonth()]);
    $invoice = SubscriptionInvoice::query()->create(['saas_plan_id' => $plan->id, 'months' => 1, 'computers' => 1, 'price_per_computer' => 1000, 'amount' => 1000]);
    $this->travelTo(now()->startOfMinute());

    $this->actingAs(User::factory()->superadmin()->create(['phone' => '+77009990001']))->post(route('superadmin.invoices.pay', $invoice->id))->assertSessionHasNoErrors();

    expect(ClubSubscription::query()->where('club_id', $club->id)->sole()->current_period_ends_at->equalTo(now()->addMonthNoOverflow()))->toBeTrue();
});

test('the superadmin sets the Kaspi link and cancels bills; clubs cannot reach the cabinet', function () {
    [$club, $plan, $owner] = billingClub();
    $invoice = SubscriptionInvoice::query()->create(['saas_plan_id' => $plan->id, 'months' => 1, 'computers' => 1, 'price_per_computer' => 1000, 'amount' => 1000]);

    $this->actingAs($owner)->post(route('superadmin.invoices.pay', $invoice->id))->assertForbidden();

    $this->actingAs(User::factory()->superadmin()->create(['phone' => '+77009990001']));
    $this->post(route('superadmin.invoices.kaspi'), ['url' => 'http://pay.kaspi.kz/x'])->assertSessionHasErrors('url');
    $this->post(route('superadmin.invoices.kaspi'), ['url' => 'https://pay.kaspi.kz/pay/new', 'recipient' => 'ТОО Zafiro'])->assertSessionHasNoErrors();
    expect(app(SubscriptionBilling::class)->kaspi())->toBe(['url' => 'https://pay.kaspi.kz/pay/new', 'recipient' => 'ТОО Zafiro']);

    $this->post(route('superadmin.invoices.cancel', $invoice->id))->assertSessionHasNoErrors();
    expect($invoice->fresh()->status)->toBe('canceled');
});

test('a new bill is reported to the platform telegram chat', function () {
    [$club, $plan, $owner] = billingClub(2);
    PlatformSetting::write(TelegramAlerts::SETTING, ['token' => Crypt::encryptString('123:abc'), 'chat_id' => '42', 'locale' => 'ru'], null);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

    $this->actingAs($owner)->post(route('billing.invoices.store'), ['saas_plan_id' => $plan->id, 'months' => 1])->assertRedirect();

    $invoice = SubscriptionInvoice::query()->sole();
    Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/sendMessage')
        && str_contains($request['text'], 'Счёт '.$invoice->number().': '.$club->name)
        && str_contains($request['text'], 'Базовый, 1 мес., 2 ПК')
        && str_contains($request['text'], 'К оплате 2 000 ₸')
        && str_contains($request['text'], route('superadmin.invoices.index')));
});
