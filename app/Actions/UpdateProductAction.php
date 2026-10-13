<?php

namespace App\Actions;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\ActivityLogger;
use App\Services\Integrations\WebhookDispatcher;
use Illuminate\Support\Facades\DB;

/** Changes product details. Stock is changed only through stock movements. */
class UpdateProductAction
{
    public function __construct(private ActivityLogger $activity, private WebhookDispatcher $webhooks) {}

    /** @param  array{sku?: string, name?: string, product_category_id?: int, price?: numeric}  $data */
    public function handle(Product $product, array $data, int $userId): Product
    {
        return DB::transaction(function () use ($product, $data, $userId): Product {
            $product = Product::query()->lockForUpdate()->findOrFail($product->getKey());

            if (isset($data['product_category_id'])) {
                $data['category'] = ProductCategory::query()->findOrFail($data['product_category_id'])->name;
            }

            $product->fill($data);
            $changes = collect($product->getDirty())->except('updated_at')
                ->map(fn (mixed $value, string $field): string => $product->getOriginal($field).' → '.$value)
                ->all();

            if ($changes === []) {
                return $product;
            }

            $product->save();
            $this->activity->record('product.updated', __('Изменён товар: :product', ['product' => $product->name]), $userId, $product, $changes);
            $this->webhooks->dispatch('product.updated', ['product_id' => $product->id, 'sku' => $product->sku, 'name' => $product->name, 'price' => (string) $product->price, 'changed' => array_keys($changes)]);

            return $product;
        });
    }
}
