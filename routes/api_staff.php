<?php

use App\Http\Controllers\Api\Staff\CashController;
use App\Http\Controllers\Api\Staff\ComputerController;
use App\Http\Controllers\Api\Staff\CustomerController;
use App\Http\Controllers\Api\Staff\MeController;
use App\Http\Controllers\Api\Staff\OrderController;
use App\Http\Controllers\Api\Staff\ProductController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ClubComputerController;
use App\Http\Controllers\GuestSessionController;
use App\Http\Controllers\NotificationController;
use App\Services\ClubComputerSessions;
use App\Support\StaffPermissions;
use Illuminate\Support\Facades\Route;

// Staff mobile app (docs/staff-api.md). Every route: a staff member of the token's club; the
// narrower groups use the rules of the same pages of the web panel (routes/web.php): roles, or
// the employee's rights (ClubPermissions) for the cash register and the warehouse.
$roles = fn (array $roles): string => 'club-role:'.implode(',', $roles);

Route::get('/me', [MeController::class, 'show'])->name('me');

// Club map
Route::get('/computers', [ComputerController::class, 'index'])->name('computers.index');
Route::post('/computers/control', [ClubComputerController::class, 'control'])->name('computers.control');
Route::post('/computers/{computer}/end-session', [ClubComputerController::class, 'endSession'])
    ->middleware($roles(ClubComputerSessions::STAFF_ROLES))->name('computers.end-session');
Route::post('/computers/guest-session', [GuestSessionController::class, 'store'])
    ->middleware(['club-can:cash_register', 'throttle:30,1,api.staff.guest-session'])->name('computers.guest-session');

// Cash register
Route::middleware('club-can:cash_register')->prefix('cash')->name('cash.')->group(function (): void {
    Route::get('/', [CashController::class, 'show'])->name('show');
    Route::post('/shifts', [CashController::class, 'open'])->name('shifts.open');
    Route::post('/shifts/{shift}/close', [CashController::class, 'close'])->name('shifts.close');
    Route::post('/top-ups', [CashController::class, 'topUp'])->name('top-ups.store');
    Route::post('/sales', [CashController::class, 'sale'])->name('sales.store');
    Route::post('/transactions', [CashController::class, 'transact'])->name('transactions.store');
});

// Customers and goods
Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
Route::get('/products', [ProductController::class, 'index'])->name('products.index');
Route::post('/stock/receive', [ProductController::class, 'receive'])->name('stock.receive');
Route::post('/stock/movements', [ProductController::class, 'move'])->middleware('club-can:warehouse')->name('stock.movements.store');

// Orders from the PC agent
Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
Route::post('/orders/{order}/done', [OrderController::class, 'done'])->name('orders.done');
Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');

// Chat (reading: everyone; answering: cashiers and operators, checked by the controller)
Route::get('/chats', [ChatController::class, 'conversationsJson'])->name('chats.index');
Route::get('/chat/poll', [ChatController::class, 'poll'])->name('chat.poll');
Route::post('/chat/{customer}', [ChatController::class, 'reply'])->middleware('throttle:60,1,api.staff.chat.reply')->name('chat.reply');

// Notifications and the day report
Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
Route::post('/notifications/seen', [NotificationController::class, 'markSeen'])->name('notifications.seen');
Route::get('/reports/today', [MeController::class, 'today'])->middleware($roles(StaffPermissions::REPORTS))->name('reports.today');
