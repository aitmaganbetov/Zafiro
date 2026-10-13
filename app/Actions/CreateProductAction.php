<?php

namespace App\Actions;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\StockMovement;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

class CreateProductAction
{
    public function __construct(private ActivityLogger $activity) {}

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
                "Создан товар: {$product->name}",
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
                    'note' => 'Начальный остаток при создании товара',
                ]);
                $this->activity->record(
                    'stock.received',
                    "Начальное оприходование: {$product->name}, {$quantity} шт.",
                    $userId,
                    $product,
                    ['quantity' => $quantity, 'balance_after' => $quantity, 'note' => 'Начальный остаток'],
                );
            }

            return $product;
        });
    }
}
