<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\AdjustStockAction;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\CurrentClub;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StockMovementController extends ApiV1Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['product_id' => ['nullable', 'integer']]);
        $query = StockMovement::query()->when($filters['product_id'] ?? null, fn ($q, int $id) => $q->where('product_id', $id));

        return $this->paginate($request, $query, Transformers::stockMovement(...));
    }

    public function store(Request $request, AdjustStockAction $adjust): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', Rule::exists((new Product)->getTable(), 'id')->where('club_id', app(CurrentClub::class)->id())],
            'type' => ['required', Rule::in([StockMovement::TYPE_RECEIPT, StockMovement::TYPE_WRITE_OFF])],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json(['data' => Transformers::stockMovement($adjust->handle($data, $this->actorId($request)))], 201);
    }
}
