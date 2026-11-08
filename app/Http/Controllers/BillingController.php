<?php

namespace App\Http\Controllers;

use App\Models\ClubSubscription;
use App\Models\SubscriptionInvoice;
use App\Services\ActivityLogger;
use App\Services\CurrentClub;
use App\Services\Monitoring\TelegramAlerts;
use App\Services\SubscriptionBilling;
use App\Support\ClubPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BillingController extends Controller
{
    public function __construct(private CurrentClub $currentClub, private SubscriptionBilling $billing) {}

    public function show(): View
    {
        $subscription = ClubSubscription::query()
            ->with('plan')
            ->where('club_id', $this->currentClub->id())
            ->first();
        $invoices = SubscriptionInvoice::query()->with('plan:id,name')->latest('id')->limit(12)->get();

        return view('billing.index', [
            'subscription' => $subscription,
            'canPay' => ClubPermissions::allows($this->currentClub->membership(), 'billing.pay'),
            'plans' => $this->billing->payablePlans(),
            'computers' => $this->billing->computers($this->currentClub->get()),
            'discount' => $subscription?->discountPercent() ?? 0,
            'kaspi' => $this->billing->kaspi(),
            'pending' => $invoices->firstWhere('status', 'pending'),
            'invoices' => $invoices,
        ]);
    }

    public function store(Request $request, ActivityLogger $activity, TelegramAlerts $alerts): RedirectResponse
    {
        $data = $request->validate([
            'saas_plan_id' => ['required', 'integer', Rule::in($this->billing->payablePlans()->modelKeys())],
            'months' => ['required', 'integer', Rule::in(SubscriptionInvoice::MONTHS)],
        ]);
        if ($this->billing->kaspi() === null) {
            return back()->withErrors(['invoice' => __('Оплата пока не настроена. Напишите в поддержку Zafiro.')]);
        }

        $club = $this->currentClub->get();
        $plan = $this->billing->payablePlans()->find($data['saas_plan_id']);
        $invoice = $this->billing->open($club, $plan, (int) $data['months'], $request->user());
        $activity->record('billing.invoice_opened', __('Выставлен счёт :number на :amount ₸', ['number' => $invoice->number(), 'amount' => number_format((float) $invoice->amount, 0, '.', ' ')]), $request->user()->getKey(), $invoice);

        // After the response: a slow Telegram must not hold up the page.
        app()->terminating(fn () => $alerts->invoiceOpened($invoice, $club));

        return redirect()->route('billing.show')->with('success', __('Счёт выставлен. Оплатите его через Kaspi.'));
    }

    public function cancel(Request $request, SubscriptionInvoice $invoice, ActivityLogger $activity): RedirectResponse
    {
        $this->billing->cancel($invoice);
        $activity->record('billing.invoice_canceled', __('Отменён счёт :number', ['number' => $invoice->number()]), $request->user()->getKey(), $invoice);

        return redirect()->route('billing.show')->with('success', __('Счёт отменён.'));
    }
}
