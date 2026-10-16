<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** What is in stock, for cashiers and operators: products, categories, prices and quantities. */
class StockController extends Controller
{
    public function __invoke(Request $request): View
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
            'images' => $products->getCollection()->mapWithKeys(fn (Product $product): array => [$product->id => (new ProductResource($product))->toArray($request)['image_url']]),
        ]);
    }
}
