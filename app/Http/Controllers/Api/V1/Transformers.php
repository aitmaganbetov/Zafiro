<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\CashTransaction;
use App\Models\ClubComputer;
use App\Models\ClubMembership;
use App\Models\ClubZone;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\StockMovement;
use App\Models\Tariff;

/**
 * Public v1 representations. Only fields listed here leave the server; network
 * details of PCs, e-mail addresses and full phone numbers are never exposed.
 */
final class Transformers
{
    public static function product(Product $product): array
    {
        return [
            'id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'category_id' => $product->product_category_id,
            'category' => $product->category,
            'price' => (string) $product->price,
            'quantity' => $product->quantity,
            'updated_at' => $product->updated_at?->toIso8601String(),
        ];
    }

    public static function category(ProductCategory $category): array
    {
        return ['id' => $category->id, 'name' => $category->name];
    }

    public static function stockMovement(StockMovement $movement): array
    {
        return [
            'id' => $movement->id,
            'product_id' => $movement->product_id,
            'type' => $movement->type,
            'quantity' => $movement->quantity,
            'balance_after' => $movement->balance_after,
            'note' => $movement->note,
            'created_at' => $movement->created_at?->toIso8601String(),
        ];
    }

    public static function sale(CashTransaction $sale): array
    {
        return [
            'id' => $sale->id,
            'cash_shift_id' => $sale->cash_shift_id,
            'amount' => (string) $sale->amount,
            'items' => collect($sale->receipt_data['items'] ?? [])->map(fn (array $item): array => [
                'name' => (string) ($item['name'] ?? ''),
                'quantity' => (int) ($item['quantity'] ?? 0),
                'price' => number_format((float) ($item['price'] ?? 0), 2, '.', ''),
                'total' => number_format((float) ($item['total'] ?? 0), 2, '.', ''),
            ])->values()->all(),
            'created_at' => $sale->created_at?->toIso8601String(),
        ];
    }

    public static function zone(ClubZone $zone): array
    {
        return [
            'id' => $zone->id,
            'name' => $zone->name,
            'description' => $zone->description,
            'capacity' => $zone->capacity,
            'status' => $zone->status,
            'computers_count' => $zone->computers_count ?? null,
        ];
    }

    public static function computer(ClubComputer $computer): array
    {
        return [
            'id' => $computer->id,
            'zone_id' => $computer->club_zone_id,
            'number' => $computer->number,
            'name' => $computer->displayName(),
            'status' => $computer->currentStatus(),
            'connection' => $computer->connectionStatus(),
            'is_reserved' => $computer->is_reserved,
            'in_session' => $computer->current_session_id !== null,
            'last_seen_at' => $computer->last_seen_at?->toIso8601String(),
        ];
    }

    public static function tariff(Tariff $tariff): array
    {
        return [
            'id' => $tariff->id,
            'name' => $tariff->name,
            'description' => $tariff->description,
            'duration_minutes' => $tariff->duration_minutes,
            'price' => (string) $tariff->price,
            'currency' => $tariff->currency,
            'is_active' => $tariff->is_active,
            'zone_ids' => $tariff->relationLoaded('zones') ? $tariff->zones->pluck('id')->values()->all() : [],
        ];
    }

    public static function member(ClubMembership $membership): array
    {
        return [
            'id' => $membership->user_id,
            'name' => $membership->user?->name,
            'phone_masked' => self::maskPhone((string) $membership->user?->phone),
            'role' => $membership->role,
            'balance' => (string) ($membership->balance->balance ?? '0.00'),
            'bonus_balance' => (string) ($membership->balance->bonus_balance ?? '0.00'),
        ];
    }

    /** +77001234567 → +7 *** *** 45 67 */
    public static function maskPhone(string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', $phone);

        return strlen($digits) < 6 ? null : '+'.substr($digits, 0, 1).' *** *** '.substr($digits, -4, 2).' '.substr($digits, -2);
    }
}
