<?php

use App\Http\Resources\ProductResource;
use App\Models\ClubMembership;
use App\Models\Product;
use App\Models\User;
use App\Services\CashRegisterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

uses(RefreshDatabase::class);

test('the products API gives the PC agent the product photo as an absolute URL', function () {
    Storage::fake('public');
    Storage::disk('public')->put('products/cola.jpg', 'jpg');
    $request = Request::create(config('app.url').'/api/products');

    $withPhoto = (new Product)->forceFill(['id' => 1, 'name' => 'Cola', 'price' => 700, 'quantity' => 3, 'image_path' => 'products/cola.jpg']);
    $missing = (new Product)->forceFill(['id' => 2, 'name' => 'Fanta', 'price' => 700, 'quantity' => 3, 'image_path' => 'products/gone.jpg']);

    expect((new ProductResource($withPhoto))->toArray($request)['image_url'])->toBe(config('app.url').'/storage/products/cola.jpg')
        ->and((new ProductResource($missing))->toArray($request)['image_url'])->toBe(config('app.url').'/images/product-placeholder.svg');
});

test('the cash register shows the product photo on the card and in the order', function () {
    $cashier = User::factory()->create();
    ClubMembership::query()->where('user_id', $cashier->id)->update(['role' => 'cashier']);
    $product = Product::factory()->create(['name' => 'Cola', 'quantity' => 5]);
    $url = ProductResource::imageUrl($product, request());
    app(CashRegisterService::class)->open(0, $cashier->id);

    $this->actingAs($cashier)->get(route('cash-register.index'))->assertOk()
        ->assertSee('<img src="'.$url.'" alt="Cola" loading="lazy">', false)
        ->assertSee('data-image="'.$url.'"', false);
});
