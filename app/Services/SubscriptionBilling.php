<?php

namespace App\Services;

use App\Models\Club;
use App\Models\ClubComputer;
use App\Models\ClubSubscription;
use App\Models\PlatformSetting;
use App\Models\Reseller;
use App\Models\SaasPlan;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Paying for the subscription: the club opens a bill (plan price × its PCs × months, less its
 * discount), pays it by the platform's Kaspi Pay link with the bill number in the comment, and
 * the superadmin marks it paid once the money is in — that extends the subscription.
 */
class SubscriptionBilling
{
    /** The platform's Kaspi Pay link and payee, set in the superadmin cabinet. */
    public const KASPI_SETTING = 'billing_kaspi';

    public function __construct(private PlatformLogger $platformLog) {}

    /** @return array{url: string, recipient: string|null}|null */
    public function kaspi(): ?array
    {
        $value = PlatformSetting::read(self::KASPI_SETTING);

        return isset($value['url']) ? ['url' => (string) $value['url'], 'recipient' => $value['recipient'] ?? null] : null;
    }

    /** @return Collection<int, SaasPlan> plans a club can pay for: switched on and priced */
    public function payablePlans(): Collection
    {
        return SaasPlan::query()->where('is_active', true)->whereNotNull('price_monthly')
            ->orderBy('price_monthly')->orderBy('id')->get(['id', 'name', 'price_monthly']);
    }

    /** PCs the club pays for: all registered ones, at least one. */
    public function computers(Club $club): int
    {
        return max(1, ClubComputer::query()->withoutGlobalScope('club')->where('club_id', $club->getKey())->count());
    }

    public function amount(float $pricePerComputer, int $computers, int $months, int $discountPercent): float
    {
        return round($pricePerComputer * $computers * $months * (100 - $discountPercent) / 100);
    }

    /**
     * The club's discount on the subscription: its own (set by the superadmin) or its reseller's,
     * whichever is larger.
     */
    public function discountFor(Club $club, ?ClubSubscription $subscription = null): int
    {
        $subscription ??= ClubSubscription::query()->where('club_id', $club->getKey())->first();
        $reseller = $club->reseller_id === null ? null : Reseller::query()->where('is_active', true)->find($club->reseller_id);

        return max($subscription?->discountPercent() ?? 0, $reseller?->discount_percent ?? 0);
    }

    /** A club has one open bill at a time. */
    public function open(Club $club, SaasPlan $plan, int $months, User $by): SubscriptionInvoice
    {
        return DB::transaction(function () use ($club, $plan, $months, $by): SubscriptionInvoice {
            $subscription = ClubSubscription::query()->where('club_id', $club->getKey())->lockForUpdate()->first();
            if (SubscriptionInvoice::query()->withoutGlobalScope('club')->where('club_id', $club->getKey())->where('status', 'pending')->exists()) {
                throw ValidationException::withMessages(['invoice' => __('У клуба уже есть неоплаченный счёт: оплатите или отмените его.')]);
            }

            $computers = $this->computers($club);
            $discount = $this->discountFor($club, $subscription);
            $reseller = $club->reseller_id === null ? null : Reseller::query()->where('is_active', true)->find($club->reseller_id);

            return SubscriptionInvoice::query()->create([
                'reseller_id' => $reseller?->getKey(),
                'reseller_percent' => $reseller?->commission_percent,
                'saas_plan_id' => $plan->getKey(),
                'months' => $months,
                'computers' => $computers,
                'price_per_computer' => $plan->price_monthly,
                'discount_percent' => $discount,
                'amount' => $this->amount((float) $plan->price_monthly, $computers, $months, $discount),
                'created_by' => $by->getKey(),
            ]);
        });
    }

    /** The money is in: the subscription runs N more months from its current end (or from today). */
    public function markPaid(int $invoiceId, User $by): SubscriptionInvoice
    {
        return DB::transaction(function () use ($invoiceId, $by): SubscriptionInvoice {
            $invoice = SubscriptionInvoice::query()->withoutGlobalScope('club')->with('club:id,name')->lockForUpdate()->findOrFail($invoiceId);
            if ($invoice->status !== 'pending') {
                throw ValidationException::withMessages(['invoice' => __('Счёт уже закрыт.')]);
            }

            $subscription = ClubSubscription::query()->where('club_id', $invoice->club_id)->lockForUpdate()->first()
                ?? (new ClubSubscription)->forceFill(['club_id' => $invoice->club_id, 'started_at' => now()]);
            /** @var Carbon $from */
            $from = collect([
                now(),
                $subscription->status === 'active' ? $subscription->current_period_ends_at : null,
                $subscription->status === 'trialing' ? $subscription->trial_ends_at : null,
            ])->filter()->max();
            $until = $from->copy()->addMonthsNoOverflow($invoice->months);

            $subscription->forceFill(['saas_plan_id' => $invoice->saas_plan_id, 'status' => 'active', 'current_period_ends_at' => $until, 'canceled_at' => null])->save();
            // The reseller earns their percent of the money paid.
            $earning = $invoice->reseller_id === null ? null : round((float) $invoice->amount * (float) $invoice->reseller_percent / 100, 2);
            $invoice->forceFill(['status' => 'paid', 'paid_at' => now(), 'confirmed_by' => $by->getKey(), 'period_ends_at' => $until, 'reseller_amount' => $earning])->save();

            $this->platformLog->record('superadmin.invoice_paid', __('Счёт :number оплачен: подписка клуба :club продлена до :date', [
                'number' => $invoice->number(),
                'club' => $invoice->club->name,
                'date' => $until->format('d.m.Y'),
            ]), $by, $invoice->club, $invoice, ['amount' => (float) $invoice->amount, 'months' => $invoice->months]);

            return $invoice;
        });
    }

    public function cancel(SubscriptionInvoice $invoice): void
    {
        DB::transaction(function () use ($invoice): void {
            $locked = SubscriptionInvoice::query()->withoutGlobalScope('club')->lockForUpdate()->findOrFail($invoice->getKey());
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['invoice' => __('Счёт уже закрыт.')]);
            }
            $locked->forceFill(['status' => 'canceled'])->save();
        });
    }
}
