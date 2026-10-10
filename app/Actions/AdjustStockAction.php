<?php

namespace App\Actions;

use App\Models\Product;
use App\Models\StockMovement;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdjustStockAction
{
    public function __construct(private ActivityLogger $activity) {}

    public function handle(array $data, ?int $userId): StockMovement
    {
        return DB::transaction(function () use ($data, $userId): StockMovement {
            $product = Product::query()
                ->lockForUpdate()
                ->findOrFail($data['product_id']);

            $quantity = (int) $data['quantity'];

            if ($data['type'] === StockMovement::TYPE_WRITE_OFF && $quantity > $product->quantity) {
                throw ValidationException::withMessages([
                    'quantity' => 'Нельзя списать больше, чем есть на складе.',
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
                ($isReceipt ? 'Оприходование: ' : 'Списание: ')."{$product->name}, {$quantity} шт.",
                $userId,
                $product,
                ['quantity' => $quantity, 'balance_after' => $product->quantity, 'note' => $movement->note ?: '—'],
            );

            return $movement;
        });
    }
}
