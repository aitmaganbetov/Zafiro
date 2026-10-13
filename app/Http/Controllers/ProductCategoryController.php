<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProductCategoryRequest;
use App\Models\ProductCategory;
use App\Services\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class ProductCategoryController extends Controller
{
    public function __invoke(StoreProductCategoryRequest $request, ActivityLogger $activity): RedirectResponse
    {
        DB::transaction(function () use ($request, $activity): void {
            $category = ProductCategory::query()->create($request->validated());
            $activity->record('warehouse.category_created', __('Создана категория склада: :category', ['category' => $category->name]), $request->user()->getKey(), $category);
        });

        return back()->with('status', __('Категория создана.'));
    }
}
