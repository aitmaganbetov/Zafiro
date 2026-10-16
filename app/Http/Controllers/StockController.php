<?php

namespace App\Http\Controllers;

use App\Actions\AdjustStockAction;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\CurrentClub;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Stock for cashiers and operators: what is in stock, and receiving goods (the same stock
 * operation as in the warehouse: logged, webhooks). Write-offs stay with the managers.
 */
class StockController extends Controller
{
    public function receive(Request $request, AdjustStockAction $adjustStock): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('club_id', app(CurrentClub::class)->id())],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $adjustStock->handle([...$data, 'type' => StockMovement::TYPE_RECEIPT], $request->user()->getKey());

        return back()->with('status', __('Товар оприходован.'));
    }

    public function index(Request $request): View
    {
        $search = trim((string) ($request->validate(['search' => ['nullable', 'string', 'max:100']])['search'] ?? ''));
        $products = Product::query()
            ->when($search !== '', fn ($query) => $query->where(fn ($match) => $match
                ->where('name', 'like', '%'.addcslashes($search, '\\%_').'%')
                ->orWhere('sku', 'like', '%'.addcslashes($search, '\\%_').'%')))
            ->orderBy('category')->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        return view('stock.index', [
            'products' => $products,
            'search' => $search,
            'images' => $products->getCollection()->mapWithKeys(fn (Product $product): array => [$product->id => ProductResource::imageUrl($product, $request)]),
        ]);
    }
}
