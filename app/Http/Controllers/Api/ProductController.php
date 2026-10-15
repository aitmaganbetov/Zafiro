<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductController extends Controller
{
    public function __invoke(): AnonymousResourceCollection
    {
        // All columns: the photo path too, where the products table has it.
        $products = Product::query()
            ->orderBy('name')
            ->paginate(50);

        return ProductResource::collection($products);
    }
}
