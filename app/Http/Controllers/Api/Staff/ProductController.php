<?php

namespace App\Http\Controllers\Api\Staff;

use App\Actions\AdjustStockAction;
use App\Http\Requests\StoreStockMovementRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\CurrentClub;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Goods of the staff app: the list with stock (POS sale, warehouse), receiving and writing off. */
class ProductController extends StaffApiController
{
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) ($request->validate(['search' => ['nullable', 'string', 'max:100']])['search'] ?? ''));
        $like = '%'.addcslashes($search, '\\%_').'%';
        $page = Product::query()
            ->when($search !== '', fn ($query) => $query->where(fn ($products) => $products->where('name', 'like', $like)->orWhere('sku', 'like', $like)))
            ->orderBy('category')->orderBy('name')
            ->paginate(50);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Product $product): array => [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'category' => $product->category,
                'price' => self::money($product->price),
                'quantity' => (int) $product->quantity,
                'image_url' => ProductResource::imageUrl($product, $request),
            ])->all(),
            'meta' => self::meta($page),
        ]);
    }

    /** Receiving goods: every staff member (as stock.receive of the panel). */
    public function receive(Request $request, AdjustStockAction $adjustStock): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('club_id', app(CurrentClub::class)->id())],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $movement = $adjustStock->handle([...$data, 'type' => StockMovement::TYPE_RECEIPT], $request->user()->getKey());

        return response()->json(['product_id' => $movement->product_id, 'quantity' => (int) $movement->balance_after]);
    }

    /** Receiving and writing off: managers (as warehouse.stock-movements of the panel). */
    public function move(StoreStockMovementRequest $request, AdjustStockAction $adjustStock): JsonResponse
    {
        $movement = $adjustStock->handle($request->validated(), $request->user()->getKey());

        return response()->json(['product_id' => $movement->product_id, 'quantity' => (int) $movement->balance_after]);
    }
}
