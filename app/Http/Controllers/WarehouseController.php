<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\StockMovement;
use Illuminate\View\View;

class WarehouseController extends Controller
{
    public function __invoke(): View
    {
        $products = Product::query()
            ->select(['id', 'name', 'quantity'])
            ->orderBy('name')
            ->get();
        $stock = Product::query()
            ->select(['id', 'sku', 'name', 'category', 'price', 'quantity'])
            ->orderBy('name')
            ->paginate(50);

        $movements = StockMovement::query()
            ->select(['id', 'product_id', 'type', 'quantity', 'balance_after', 'note', 'created_at'])
            ->with('product:id,name')
            ->latest()
            ->limit(10)
            ->get();

        return view('warehouse', [
            'products' => $products,
            'stock' => $stock,
            'categories' => ProductCategory::query()->withCount('products')->orderBy('name')->get(),
            'movements' => $movements,
        ]);
    }
}
