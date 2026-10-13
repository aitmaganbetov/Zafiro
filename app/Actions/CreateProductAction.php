<?php

namespace App\Actions;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\StockMovement;
use App\Services\ActivityLogger;
use App\Services\Integrations\WebhookDispatcher;
use Illuminate\Support\Facades\DB;

class CreateProductAction
{
    public function __construct(private ActivityLogger $activity, private WebhookDispatcher $webhooks) {}

    public function handle(array $data, int $userId): Product
    {
        return DB::transaction(function () use ($data, $userId): Product {
            $category = ProductCategory::query()->findOrFail($data['product_category_id']);
            $quantity = (int) $data['quantity'];
            $product = Product::query()->create([
                ...$data,
                'category' => $category->name,
                'quantity' => $quantity,
            ]);
            $this->activity->record(
                'product.created',
                __('Создан товар: :product', ['product' => $product->name]),
                $userId,
                $product,
                ['sku' => $product->sku, 'quantity' => $quantity],
            );

            if ($quantity > 0) {
                $product->stockMovements()->create([
                    'user_id' => $userId,
                    'type' => StockMovement::TYPE_RECEIPT,
                    'quantity' => $quantity,
                    'balance_after' => $quantity,
                    'note' => __('Начальный остаток при создании товара'),
                ]);
                $this->activity->record(
                    'stock.received',
                    __('Начальное оприходование: :product, :quantity шт.', ['product' => $product->name, 'quantity' => $quantity]),
                    $userId,
                    $product,
                    ['quantity' => $quantity, 'balance_after' => $quantity, 'note' => __('Начальный остаток')],
                );
                $this->webhooks->dispatch('stock.changed', ['product_id' => $product->id, 'sku' => $product->sku, 'change' => $quantity, 'quantity' => $quantity, 'reason' => 'initial']);
            }

            $this->webhooks->dispatch('product.created', ['product_id' => $product->id, 'sku' => $product->sku, 'name' => $product->name, 'price' => (string) $product->price, 'quantity' => $quantity]);

            return $product;
        });
    }
}
