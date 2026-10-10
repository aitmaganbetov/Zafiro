<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Database\Seeder;

class ProductCategorySeeder extends Seeder
{
    public function run(): void
    {
        Product::query()->select('category')->distinct()->pluck('category')->filter()->each(function (string $name): void {
            $category = ProductCategory::query()->firstOrCreate(['name' => $name]);
            Product::query()->where('category', $name)->update(['product_category_id' => $category->id]);
        });
    }
}
