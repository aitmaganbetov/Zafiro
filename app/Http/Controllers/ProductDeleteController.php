<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a product (the "warehouse.delete" right, the owner by default): it leaves the cash
 * register, the warehouse and the agent shop; its stock movements and receipts stay. Its SKU is
 * freed for a new product (SKUs are unique in the club).
 */
class ProductDeleteController extends Controller
{
    public function __invoke(Request $request, Product $product, ActivityLogger $activity): RedirectResponse
    {
        DB::transaction(function () use ($request, $product, $activity): void {
            $sku = $product->sku;
            $product->forceFill(['sku' => mb_substr($sku, 0, 200).'~deleted-'.$product->getKey()])->save();
            $product->delete();
            $activity->record('product.deleted', __('Удалён товар: :product', ['product' => $product->name]), $request->user()->getKey(), $product, [
                'sku' => $sku,
                'quantity' => (int) $product->quantity,
            ]);
        });

        return redirect()->route('warehouse')->with('status', __('Товар «:product» удалён.', ['product' => $product->name]));
    }
}
