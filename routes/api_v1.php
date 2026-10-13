<?php

/*
 * Integration API v1 for club systems (key auth, see docs/integration-api.md).
 * Every route must declare api.scope:<scope> (or api.scope:any); routes without it
 * are rejected by AuthenticateApiKey. The club always comes from the API key, and
 * bound models ({product}, {computer}) resolve only inside that club (404 otherwise).
 */

use App\Http\Controllers\Api\V1\ComputerController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\MemberController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\SaleController;
use App\Http\Controllers\Api\V1\StockMovementController;
use App\Http\Controllers\Api\V1\TariffController;
use Illuminate\Support\Facades\Route;

Route::get('/me', MeController::class)->middleware('api.scope:any')->name('me');

// Products and stock
Route::middleware('api.scope:products:read')->group(function () {
    Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    Route::get('/products/{product}', [ProductController::class, 'show'])->whereNumber('product')->name('products.show');
    Route::get('/product-categories', [ProductController::class, 'categories'])->name('product-categories.index');
    Route::get('/stock-movements', [StockMovementController::class, 'index'])->name('stock-movements.index');
});
Route::middleware(['api.scope:products:write', 'api.idempotent'])->group(function () {
    Route::post('/products', [ProductController::class, 'store'])->name('products.store');
    Route::patch('/products/{product}', [ProductController::class, 'update'])->whereNumber('product')->name('products.update');
});
Route::post('/stock-movements', [StockMovementController::class, 'store'])
    ->middleware(['api.scope:stock:write', 'api.idempotent:required'])
    ->name('stock-movements.store');

// Sales and cash register (read only)
Route::middleware('api.scope:sales:read')->group(function () {
    Route::get('/sales', [SaleController::class, 'index'])->name('sales.index');
    Route::get('/sales/{sale}', [SaleController::class, 'show'])->whereNumber('sale')->name('sales.show');
});

// PCs and zones
Route::middleware('api.scope:computers:read')->group(function () {
    Route::get('/zones', [ComputerController::class, 'zones'])->name('zones.index');
    Route::get('/computers', [ComputerController::class, 'index'])->name('computers.index');
    Route::get('/computers/{computer}', [ComputerController::class, 'show'])->whereNumber('computer')->name('computers.show');
});
Route::put('/computers/{computer}/reservation', [ComputerController::class, 'reservation'])
    ->whereNumber('computer')
    ->middleware(['api.scope:bookings:write', 'api.idempotent'])
    ->name('computers.reservation');
Route::post('/computers/control', [ComputerController::class, 'control'])
    ->middleware(['api.scope:computers:control', 'api.idempotent:required'])
    ->name('computers.control');

// Tariffs and members
Route::get('/tariffs', [TariffController::class, 'index'])->middleware('api.scope:tariffs:read')->name('tariffs.index');
Route::get('/members', [MemberController::class, 'index'])->middleware('api.scope:members:read')->name('members.index');
