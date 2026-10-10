<?php

use App\Models\User;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\StockMovement;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('category and product can be created in warehouse', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('warehouse.categories.store'), [
        'name' => 'Десерты',
    ])->assertRedirect()->assertSessionHas('status');

    $category = ProductCategory::query()->firstOrFail();
    $this->assertModelExists($category);

    $this->actingAs($user)->post(route('warehouse.products.store'), [
        'sku' => 'DST-001',
        'name' => 'Чизкейк',
        'product_category_id' => $category->id,
        'price' => 1800,
        'quantity' => 7,
    ])->assertRedirect()->assertSessionHas('status');

    $product = Product::query()->firstOrFail();
    expect($product->sku)->toBe('DST-001')
        ->and($product->category)->toBe('Десерты')
        ->and($product->product_category_id)->toBe($category->id)
        ->and($product->quantity)->toBe(7);
    expect(StockMovement::query()->first())
        ->type->toBe(StockMovement::TYPE_RECEIPT)
        ->quantity->toBe(7)
        ->balance_after->toBe(7);
});

test('authenticated user can open warehouse page', function () {
    $this->seed(ProductSeeder::class);

    $user = new User([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => bcrypt('secret'),
    ]);

    $this->actingAs($user)
        ->get('/warehouse')
        ->assertSuccessful()
        ->assertSee('Склад')
        ->assertSee('Товаров на складе: 10')
        ->assertSee('images/product-placeholder.svg')
        ->assertSee('Coca-Cola 0,5 л')
        ->assertSee('Кабель HDMI 2 м');
});

test('product can be received into stock', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create(['quantity' => 10]);

    $this->actingAs($user)
        ->post('/warehouse/stock-movements', [
            'product_id' => $product->id,
            'type' => StockMovement::TYPE_RECEIPT,
            'quantity' => 7,
            'note' => 'Поставка',
        ])
        ->assertRedirect()
        ->assertSessionHas('status');

    expect($product->refresh()->quantity)->toBe(17);
    expect(StockMovement::query()->first())
        ->type->toBe(StockMovement::TYPE_RECEIPT)
        ->quantity->toBe(7)
        ->balance_after->toBe(17)
        ->note->toBe('Поставка');
});

test('product can be written off from stock', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create(['quantity' => 10]);

    $this->actingAs($user)
        ->post('/warehouse/stock-movements', [
            'product_id' => $product->id,
            'type' => StockMovement::TYPE_WRITE_OFF,
            'quantity' => 4,
        ])
        ->assertRedirect();

    expect($product->refresh()->quantity)->toBe(6);
    expect(StockMovement::query()->first())
        ->type->toBe(StockMovement::TYPE_WRITE_OFF)
        ->balance_after->toBe(6);
});

test('product cannot be written off beyond available stock', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create(['quantity' => 3]);

    $this->actingAs($user)
        ->from('/warehouse')
        ->post('/warehouse/stock-movements', [
            'product_id' => $product->id,
            'type' => StockMovement::TYPE_WRITE_OFF,
            'quantity' => 4,
        ])
        ->assertRedirect('/warehouse')
        ->assertSessionHasErrors('quantity');

    expect($product->refresh()->quantity)->toBe(3);
    expect(StockMovement::query()->count())->toBe(0);
});

test('mobile API returns paginated products with image and stock data', function () {
    Product::factory()->create([
        'sku' => 'MOB-001',
        'name' => 'Мобильный товар',
        'price' => 1500,
        'quantity' => 5,
    ]);

    $this->getJson('/api/products')
        ->assertSuccessful()
        ->assertJsonPath('data.0.sku', 'MOB-001')
        ->assertJsonPath('data.0.name', 'Мобильный товар')
        ->assertJsonPath('data.0.price', 1500)
        ->assertJsonPath('data.0.currency', 'KZT')
        ->assertJsonPath('data.0.quantity', 5)
        ->assertJsonPath('data.0.in_stock', true)
        ->assertJsonPath('data.0.image_url', 'http://localhost:8000/images/product-placeholder.svg')
        ->assertJsonPath('meta.total', 1);
});
