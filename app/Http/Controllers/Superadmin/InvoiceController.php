<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Models\SubscriptionInvoice;
use App\Services\PlatformLogger;
use App\Services\SubscriptionBilling;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Club bills for the subscription: the superadmin marks them paid when the Kaspi payment arrives. */
class InvoiceController extends Controller
{
    public function __construct(private SubscriptionBilling $billing, private PlatformLogger $platformLog) {}

    public function index(): View
    {
        $query = fn () => SubscriptionInvoice::query()->withoutGlobalScope('club')->with(['club:id,name', 'plan:id,name']);

        return view('superadmin.invoices.index', [
            'pending' => $query()->where('status', 'pending')->oldest('id')->get(),
            'closed' => $query()->where('status', '!=', 'pending')->latest('id')->paginate(30),
            'kaspi' => $this->billing->kaspi(),
        ]);
    }

    public function pay(Request $request, int $invoice): RedirectResponse
    {
        $paid = $this->billing->markPaid($invoice, $request->user());

        return back()->with('status', __('Счёт :number оплачен, подписка продлена до :date.', ['number' => $paid->number(), 'date' => $paid->period_ends_at->format('d.m.Y')]));
    }

    public function cancel(Request $request, int $invoice): RedirectResponse
    {
        $model = SubscriptionInvoice::query()->withoutGlobalScope('club')->with('club:id,name')->findOrFail($invoice);
        $this->billing->cancel($model);
        $this->platformLog->record('superadmin.invoice_canceled', __('Отменён счёт :number клуба :club', ['number' => $model->number(), 'club' => $model->club->name]), $request->user(), $model->club, $model);

        return back()->with('status', __('Счёт отменён.'));
    }

    public function kaspi(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'url' => ['nullable', 'url:https', 'max:500'],
            'recipient' => ['nullable', 'string', 'max:200'],
        ]);

        PlatformSetting::write(SubscriptionBilling::KASPI_SETTING, $data['url'] ? ['url' => $data['url'], 'recipient' => $data['recipient']] : null, $request->user());
        $this->platformLog->record('superadmin.billing_kaspi_changed', $data['url'] ? __('Изменена ссылка на оплату Kaspi') : __('Оплата через Kaspi отключена'), $request->user());

        return back()->with('status', __('Настройки оплаты сохранены.'));
    }
}
