<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProductCategoryRequest;
use App\Models\ProductCategory;
use App\Services\ActivityLogger;
use Illuminate\Http\RedirectResponse;

class ProductCategoryController extends Controller
{
    public function __invoke(StoreProductCategoryRequest $request, ActivityLogger $activity): RedirectResponse
    {
        $category = ProductCategory::query()->create($request->validated());
        $activity->record('warehouse.category_created', "Создана категория склада: {$category->name}", $request->user()?->getKey(), $category);

        return back()->with('status', 'Категория создана.');
    }
}
