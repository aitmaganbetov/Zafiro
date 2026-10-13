<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\CreateProductAction;
use App\Actions\UpdateProductAction;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\CurrentClub;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductController extends ApiV1Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'sku' => ['nullable', 'string', 'max:100'],
            'search' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer'],
        ]);

        $query = Product::query()
            ->when($filters['sku'] ?? null, fn ($q, string $sku) => $q->where('sku', $sku))
            ->when($filters['search'] ?? null, fn ($q, string $search) => $q->where('name', 'like', addcslashes($search, '%_\\').'%'))
            ->when($filters['category_id'] ?? null, fn ($q, int $id) => $q->where('product_category_id', $id));

        return $this->paginate($request, $query, Transformers::product(...));
    }

    public function show(Product $product): JsonResponse
    {
        return response()->json(['data' => Transformers::product($product)]);
    }

    public function store(Request $request, CreateProductAction $create): JsonResponse
    {
        $data = $request->validate($this->rules() + [
            'quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
        ]);
        $product = $create->handle($data, $this->actorId($request));

        return response()->json(['data' => Transformers::product($product->refresh())], 201);
    }

    public function update(Request $request, Product $product, UpdateProductAction $update): JsonResponse
    {
        $data = $request->validate(collect($this->rules($product))
            ->map(fn (array $rules): array => ['sometimes', ...array_diff($rules, ['required'])])
            ->all() + ['quantity' => ['prohibited']], ['quantity.prohibited' => 'Use POST /api/v1/stock-movements to change stock.']);

        return response()->json(['data' => Transformers::product($update->handle($product, $data, $this->actorId($request)))]);
    }

    public function categories(Request $request): JsonResponse
    {
        return $this->paginate($request, ProductCategory::query(), Transformers::category(...));
    }

    /** @return array<string, list<mixed>> */
    private function rules(?Product $product = null): array
    {
        $clubId = app(CurrentClub::class)->id();

        return [
            'sku' => ['required', 'string', 'max:100', Rule::unique((new Product)->getTable(), 'sku')->where('club_id', $clubId)->ignore($product?->id)],
            'name' => ['required', 'string', 'max:150'],
            'product_category_id' => ['required', 'integer', Rule::exists((new ProductCategory)->getTable(), 'id')->where('club_id', $clubId)],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
        ];
    }
}
