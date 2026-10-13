<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\CashRegisterController;
use App\Http\Controllers\ClubComputerController;
use App\Http\Controllers\ClubComputerListController;
use App\Http\Controllers\ClubController;
use App\Http\Controllers\ClubInvitationController;
use App\Http\Controllers\ClubZoneController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\LanguageController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProductCategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StockMovementController;
use App\Http\Controllers\TariffController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WarehouseController;
use Illuminate\Support\Facades\Route;

// Public routes
Route::get('/', function () {
    return view('welcome');
});

// Integration API v1 reference (public: it describes the API, it contains no club data).
Route::view('/docs/api', 'api-docs')->name('api-docs');
Route::get('/docs/api/openapi.yaml', fn () => response()->file(resource_path('api/openapi-v1.yaml'), ['Content-Type' => 'application/yaml; charset=utf-8']))
    ->name('api-docs.spec');

Route::get('/invitations/{token}', [ClubInvitationController::class, 'show'])->name('club-invitations.show');
Route::post('/invitations/{token}', [ClubInvitationController::class, 'accept'])->middleware('throttle:10,1')->name('club-invitations.accept');

// Auth routes
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('auth.show-login');
    Route::post('/login', [AuthController::class, 'login'])->name('auth.login');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('auth.show-register');
    Route::post('/register', [AuthController::class, 'register'])->name('auth.register');
});

// Protected routes
Route::middleware(['auth', 'current-club'])->group(function () {
    Route::post('/clubs/switch', [ClubController::class, 'switch'])->name('clubs.switch');
    Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::get('/billing', [BillingController::class, 'show'])->name('billing.show');
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/seen', [NotificationController::class, 'markSeen'])->name('notifications.seen');
    Route::middleware(['subscription', 'club-role:owner,admin,manager,cashier,operator'])->group(function () {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');
    });

    Route::middleware(['subscription', 'club-role:owner,admin,manager'])->group(function () {
        Route::get('/warehouse', WarehouseController::class)->name('warehouse');
        Route::get('/activity-logs', [ActivityLogController::class, 'index'])->name('activity-logs.index');
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::post('/club-invitations', [ClubInvitationController::class, 'store'])->name('club-invitations.store');
        Route::resource('/club-map', ClubZoneController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::patch('/club-map/{clubMap}/layout', [ClubZoneController::class, 'updateLayout'])->name('club-map.layout');
        Route::post('/club-map/{clubMap}/computers', [ClubComputerController::class, 'store'])->name('club-computers.store');
        Route::post('/club-map/align', [ClubZoneController::class, 'align'])->name('club-map.align');
        Route::patch('/club-computers/{clubComputer}/layout', ClubComputerController::class)->name('club-computers.layout');
        Route::post('/warehouse/stock-movements', StockMovementController::class)->name('warehouse.stock-movements.store');
        Route::post('/warehouse/products', ProductController::class)->name('warehouse.products.store');
        Route::post('/warehouse/categories', ProductCategoryController::class)->name('warehouse.categories.store');
        Route::get('/settings', fn () => redirect()->route('settings.club'))->name('settings');
        Route::get('/settings/club', [SettingsController::class, 'club'])->name('settings.club');
        Route::get('/settings/languages', [SettingsController::class, 'languages'])->name('settings.languages');
        Route::get('/settings/finances', [SettingsController::class, 'finances'])->name('settings.finances');
        Route::get('/settings/guests', [SettingsController::class, 'guests'])->name('settings.guests');
        Route::get('/settings/api', [SettingsController::class, 'api'])->name('settings.api');
        Route::post('/settings/save', [SettingsController::class, 'save'])->name('settings.save');
        Route::post('/settings/language', [LanguageController::class, 'select'])->name('settings.language');
        Route::get('/tariffs', [TariffController::class, 'index'])->name('tariffs.index');
        Route::post('/tariffs', [TariffController::class, 'store'])->name('tariffs.store');
    });

    // Settings → API and integrations: the owner and members with the integrations permission.
    Route::middleware(['subscription', 'can:manage-integrations'])->prefix('settings/integrations')->name('settings.integrations')->group(function () {
        Route::get('/', [IntegrationController::class, 'index']);
        Route::post('/keys', [IntegrationController::class, 'storeKey'])->name('.keys.store');
        Route::post('/keys/{apiKey}/rotate', [IntegrationController::class, 'rotateKey'])->name('.keys.rotate');
        Route::delete('/keys/{apiKey}', [IntegrationController::class, 'revokeKey'])->name('.keys.revoke');
        Route::post('/webhooks', [IntegrationController::class, 'storeWebhook'])->name('.webhooks.store');
        Route::patch('/webhooks/{webhook}', [IntegrationController::class, 'updateWebhook'])->name('.webhooks.update');
        Route::post('/webhooks/{webhook}/secret', [IntegrationController::class, 'rotateWebhookSecret'])->name('.webhooks.secret');
        Route::delete('/webhooks/{webhook}', [IntegrationController::class, 'destroyWebhook'])->name('.webhooks.destroy');
        Route::post('/deliveries/{delivery}/retry', [IntegrationController::class, 'retryDelivery'])->name('.deliveries.retry');
        Route::patch('/members/{membership}', [IntegrationController::class, 'updatePermission'])->name('.permissions.update');
    });

    Route::middleware(['subscription', 'club-role:owner,admin'])->group(function () {
        Route::post('/settings/languages', [LanguageController::class, 'store'])->name('languages.store');
        Route::patch('/settings/languages/{language}', [LanguageController::class, 'update'])->name('languages.update');
        Route::delete('/settings/languages/{language}', [LanguageController::class, 'destroy'])->name('languages.destroy');
        Route::get('/settings/languages/{language}/translations', [LanguageController::class, 'translations'])->name('languages.translations');
        Route::put('/settings/languages/{language}/translations', [LanguageController::class, 'saveTranslations'])->name('languages.translations.save');
    });

    Route::middleware(['subscription', 'club-role:owner,admin,manager,cashier'])->group(function () {
        Route::get('/cash-register', [CashRegisterController::class, 'index'])->name('cash-register.index');
        Route::post('/cash-register/shifts', [CashRegisterController::class, 'open'])->name('cash-register.shifts.open');
        Route::post('/cash-register/transactions', [CashRegisterController::class, 'transaction'])->name('cash-register.transactions.store');
        Route::post('/cash-register/sales', [CashRegisterController::class, 'sale'])->name('cash-register.sales.store');
        Route::post('/cash-register/balance-top-ups', [CashRegisterController::class, 'topUpBalance'])->name('cash-register.balance-top-ups.store');
        Route::get('/cash-register/receipts/{cashTransaction}', [CashRegisterController::class, 'receipt'])->name('cash-register.receipt');
        Route::get('/cash-register/reports/x', [CashRegisterController::class, 'xReport'])->name('cash-register.x-report');
        Route::post('/cash-register/shifts/{cashShift}/close', [CashRegisterController::class, 'close'])->name('cash-register.shifts.close');
        Route::get('/cash-register/shifts/{cashShift}/z-report', [CashRegisterController::class, 'zReport'])->name('cash-register.z-report');
        Route::post('/cash-register/shifts/{cashShift}/z-report/printed', [CashRegisterController::class, 'markZReportPrinted'])->name('cash-register.z-report.printed');
    });

    Route::middleware(['subscription', 'club-role:owner,admin,manager,operator'])->group(function () {
        Route::get('/club-computers', ClubComputerListController::class)->name('club-computers.index');
        Route::post('/club-computers/control', [ClubComputerController::class, 'control'])->name('club-computers.control');
    });
});
