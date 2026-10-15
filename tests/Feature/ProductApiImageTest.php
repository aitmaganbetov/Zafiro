<?php

use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

test('the products API gives the PC agent the product photo as an absolute URL', function () {
    Storage::fake('public');
    Storage::disk('public')->put('products/cola.jpg', 'jpg');
    $request = Request::create(config('app.url').'/api/products');

    $withPhoto = (new Product)->forceFill(['id' => 1, 'name' => 'Cola', 'price' => 700, 'quantity' => 3, 'image_path' => 'products/cola.jpg']);
    $missing = (new Product)->forceFill(['id' => 2, 'name' => 'Fanta', 'price' => 700, 'quantity' => 3, 'image_path' => 'products/gone.jpg']);

    expect((new ProductResource($withPhoto))->toArray($request)['image_url'])->toBe(config('app.url').'/storage/products/cola.jpg')
        ->and((new ProductResource($missing))->toArray($request)['image_url'])->toBe(config('app.url').'/images/product-placeholder.svg');
});
