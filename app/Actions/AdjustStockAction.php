<?php

namespace App\Actions;

use App\Models\Product;
use App\Models\StockMovement;
use App\Services\ActivityLogger;
use App\Services\Integrations\WebhookDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdjustStockAction
{
    public function __construct(private ActivityLogger $activity, private WebhookDispatcher $webhooks) {}

    public function handle(array $data, int $userId): StockMovement
    {
        return DB::transaction(function () use ($data, $userId): StockMovement {
            $product = Product::query()
                ->lockForUpdate()
                ->findOrFail($data['product_id']);

            $quantity = (int) $data['quantity'];

            if ($data['type'] === StockMovement::TYPE_WRITE_OFF && $quantity > $product->quantity) {
                throw ValidationException::withMessages([
                    'quantity' => __('Нельзя списать больше, чем есть на складе.'),
                ]);
            }

            $product->quantity += $data['type'] === StockMovement::TYPE_RECEIPT
                ? $quantity
                : -$quantity;
            $product->save();

            $movement = StockMovement::create([
                'product_id' => $product->id,
                'user_id' => $userId,
                'type' => $data['type'],
                'quantity' => $quantity,
                'balance_after' => $product->quantity,
                'note' => $data['note'] ?? null,
            ]);

            $isReceipt = $data['type'] === StockMovement::TYPE_RECEIPT;
            $this->activity->record(
                $isReceipt ? 'stock.received' : 'stock.written_off',
                $isReceipt
                    ? __('Оприходование: :product, :quantity шт.', ['product' => $product->name, 'quantity' => $quantity])
                    : __('Списание: :product, :quantity шт.', ['product' => $product->name, 'quantity' => $quantity]),
                $userId,
                $product,
                ['quantity' => $quantity, 'balance_after' => $product->quantity, 'note' => $movement->note ?: '—'],
            );

            $this->webhooks->dispatch('stock.changed', [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'change' => $isReceipt ? $quantity : -$quantity,
                'quantity' => $product->quantity,
                'reason' => $data['type'],
            ]);

            return $movement;
        });
    }
}
