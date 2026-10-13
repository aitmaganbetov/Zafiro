<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\CashTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Point-of-sale receipts: income transactions that carry receipt items. */
class SaleController extends ApiV1Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'cash_shift_id' => ['nullable', 'integer'],
        ]);

        $query = $this->sales()
            ->when($filters['from'] ?? null, fn ($q, string $from) => $q->where('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, string $to) => $q->where('created_at', '<=', $to))
            ->when($filters['cash_shift_id'] ?? null, fn ($q, int $id) => $q->where('cash_shift_id', $id));

        return $this->paginate($request, $query, Transformers::sale(...));
    }

    public function show(int $sale): JsonResponse
    {
        return response()->json(['data' => Transformers::sale($this->sales()->findOrFail($sale))]);
    }

    private function sales(): Builder
    {
        return CashTransaction::query()->where('type', CashTransaction::TYPE_INCOME)->whereNotNull('receipt_data');
    }
}
