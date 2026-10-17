<?php

namespace App\Http\Controllers;

use App\Models\ShopOrder;
use App\Services\AgentShop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Orders from the club shop in the PC agent: the front desk brings them to the PC. */
class ShopOrderController extends Controller
{
    public const STATUSES = [ShopOrder::STATUS_NEW, ShopOrder::STATUS_DONE, ShopOrder::STATUS_CANCELLED];

    public function index(Request $request): View
    {
        $status = $request->validate(['status' => ['nullable', Rule::in([...self::STATUSES, 'all'])]])['status'] ?? ShopOrder::STATUS_NEW;
        $orders = ShopOrder::query()
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->with(['customer:id,name,phone', 'handler:id,name'])
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return view('shop-orders.index', ['orders' => $orders, 'status' => $status, 'newCount' => ShopOrder::newCount(), 'latest' => (int) ShopOrder::query()->max('id')]);
    }

    /** The newest order id and the number of new ones: the page reloads when they change. */
    public function state(): JsonResponse
    {
        return response()->json(['new' => ShopOrder::newCount(), 'latest' => (int) ShopOrder::query()->max('id')]);
    }

    public function done(Request $request, ShopOrder $order, AgentShop $shop): RedirectResponse
    {
        $order = $shop->handOver($order, $request->user()->getKey());

        return back()->with('status', __('Заказ №:id выдан.', ['id' => $order->id]));
    }

    public function cancel(Request $request, ShopOrder $order, AgentShop $shop): RedirectResponse
    {
        $order = $shop->cancel($order, $request->user()->getKey());

        return back()->with('status', __('Заказ №:id отменён, деньги возвращены на счёт клиента.', ['id' => $order->id]));
    }
}
