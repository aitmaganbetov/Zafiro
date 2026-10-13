<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Tariff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TariffController extends ApiV1Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['active' => ['nullable', 'boolean']]);
        $query = Tariff::query()->with('zones')
            ->when(array_key_exists('active', $filters) && $filters['active'] !== null, fn ($q) => $q->where('is_active', (bool) $filters['active']));

        return $this->paginate($request, $query, Transformers::tariff(...));
    }
}
