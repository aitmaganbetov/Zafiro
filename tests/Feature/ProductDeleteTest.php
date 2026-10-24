<?php

use App\Models\ActivityLog;
use App\Models\ClubMembership;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the owner deletes a product: off the cash register and the warehouse, history kept, SKU free again', function () {
    $owner = User::factory()->create();
    $cola = Product::factory()->create(['sku' => 'COLA-05', 'name' => 'Coca Cola', 'quantity' => 5]);
    StockMovement::query()->create(['product_id' => $cola->id, 'user_id' => $owner->id, 'type' => StockMovement::TYPE_RECEIPT, 'quantity' => 5, 'balance_after' => 5, 'note' => 'x']);

    $this->actingAs($owner)->get(route('warehouse'))->assertOk()
        ->assertSee('Удаление товара')->assertSee(route('warehouse.products.destroy', $cola), false);
    $this->delete(route('warehouse.products.destroy', $cola))->assertRedirect(route('warehouse'))->assertSessionHas('status', 'Товар «Coca Cola» удалён.');

    expect(Product::query()->exists())->toBeFalse()
        ->and(Product::withTrashed()->find($cola->id)->sku)->toBe('COLA-05~deleted-'.$cola->id)
        ->and(StockMovement::query()->where('product_id', $cola->id)->exists())->toBeTrue()
        ->and(ActivityLog::query()->where('event', 'product.deleted')->sole()->details)->toMatchArray(['sku' => 'COLA-05']);
    $this->get(route('cash-register.index'))->assertOk()->assertDontSee('data-name="Coca Cola"', false);

    // A new product may take the SKU again.
    Product::factory()->create(['sku' => 'COLA-05', 'name' => 'Coca Cola 1 л']);
    expect(Product::query()->where('sku', 'COLA-05')->count())->toBe(1);
});

test('managers do not delete products unless the owner gives the right', function () {
    User::factory()->create(); // the owner
    $manager = User::factory()->create();
    $membership = ClubMembership::query()->where('user_id', $manager->id)->sole();
    $membership->update(['role' => 'manager']);
    $cola = Product::factory()->create(['name' => 'Coca Cola']);

    $this->actingAs($manager)->get(route('warehouse'))->assertOk()->assertDontSee('Удаление товара');
    $this->delete(route('warehouse.products.destroy', $cola))->assertForbidden();

    $membership->forceFill(['permissions' => ['warehouse.delete' => true]])->save();
    $this->delete(route('warehouse.products.destroy', $cola))->assertRedirect();
    expect($cola->fresh()->trashed())->toBeTrue();
});
