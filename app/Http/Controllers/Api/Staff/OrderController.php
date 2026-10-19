<?php

namespace App\Http\Controllers\Api\Staff;

use App\Models\ShopOrder;
use App\Services\AgentShop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Goods customers ordered in the PC agent: handed over or cancelled (AgentShop, as on the panel). */
class OrderController extends StaffApiController
{
    public function index(Request $request): JsonResponse
    {
        $status = $request->validate(['status' => ['nullable', Rule::in(['new', 'done', 'cancelled', 'all'])]])['status'] ?? 'new';
        $page = ShopOrder::query()
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->with(['customer:id,name,phone', 'handler:id,name'])
            ->latest('id')
            ->paginate(30);

        return response()->json([
            'data' => $page->getCollection()->map(fn (ShopOrder $order): array => self::order($order))->all(),
            'meta' => self::meta($page),
            'new_count' => ShopOrder::newCount(),
        ]);
    }

    public function done(Request $request, ShopOrder $order, AgentShop $shop): JsonResponse
    {
        return response()->json(self::order($shop->handOver($order, $request->user()->getKey())->load(['customer:id,name,phone', 'handler:id,name'])));
    }

    public function cancel(Request $request, ShopOrder $order, AgentShop $shop): JsonResponse
    {
        return response()->json(self::order($shop->cancel($order, $request->user()->getKey())->load(['customer:id,name,phone', 'handler:id,name'])));
    }

    /** @return array<string, mixed> */
    private static function order(ShopOrder $order): array
    {
        return [
            'id' => $order->id,
            'status' => $order->status,
            'computer_name' => $order->computer_name,
            'customer' => $order->customer === null ? null : ['id' => $order->customer->id, 'name' => $order->customer->name, 'phone' => $order->customer->phone],
            'items' => collect($order->items ?? [])->filter(fn (mixed $item): bool => is_array($item))->map(fn (array $item): array => [
                'name' => (string) ($item['name'] ?? ''),
                'quantity' => (int) ($item['quantity'] ?? 0),
                'price' => self::money($item['price'] ?? 0),
                'total' => self::money($item['total'] ?? 0),
            ])->values()->all(),
            'amount' => self::money($order->amount),
            'created_at' => self::at($order->created_at),
            'handled_by' => $order->handler?->name,
            'handled_at' => self::at($order->handled_at),
        ];
    }
}
