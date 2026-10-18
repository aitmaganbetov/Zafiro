<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\CashRegisterController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ClubComputerController;
use App\Http\Controllers\ClubComputerListController;
use App\Http\Controllers\ClubController;
use App\Http\Controllers\ClubInvitationController;
use App\Http\Controllers\ClubLogoController;
use App\Http\Controllers\ClubZoneController;
use App\Http\Controllers\ComputerReservationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GuestSessionController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\LanguageController;
use App\Http\Controllers\NewsController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PasswordRecoveryController;
use App\Http\Controllers\ProductCategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PwaController;
use App\Http\Controllers\QrLoginController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\ShopOrderController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\StockMovementController;
use App\Http\Controllers\Superadmin\AgentReleaseController as SuperadminAgentReleaseController;
use App\Http\Controllers\Superadmin\ClubController as SuperadminClubController;
use App\Http\Controllers\Superadmin\DashboardController as SuperadminDashboardController;
use App\Http\Controllers\Superadmin\LanguageController as SuperadminLanguageController;
use App\Http\Controllers\Superadmin\LogController as SuperadminLogController;
use App\Http\Controllers\Superadmin\NewsController as SuperadminNewsController;
use App\Http\Controllers\Superadmin\PlanController as SuperadminPlanController;
use App\Http\Controllers\Superadmin\TelegramController as SuperadminTelegramController;
use App\Http\Controllers\Superadmin\UserController as SuperadminUserController;
use App\Http\Controllers\TariffController;
use App\Http\Controllers\TodoController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WarehouseController;
use App\Http\Controllers\WorkerController;
use App\Support\SuperadminHost;
use Illuminate\Support\Facades\Route;

// Platform superadmin cabinet: outside any club, for users with the superadmin flag. On its own
// host (SUPERADMIN_DOMAIN) from the root, registered first so its "/" wins over the landing page;
// without the host — at /superadmin. Paths differ from the club panel's (accounts, platform-news),
// because a superadmin viewing a club uses the club panel on the cabinet host.
$superadminCabinet = Route::middleware(['auth', 'superadmin']);
$superadminDomain = SuperadminHost::domain();
($superadminDomain === null ? $superadminCabinet->prefix('superadmin') : $superadminCabinet->domain($superadminDomain))->group(function () {
    Route::name('superadmin.')->group(function () {
        Route::get('/', SuperadminDashboardController::class)->name('dashboard');
        Route::put('/agent-release', [SuperadminAgentReleaseController::class, 'update'])->name('agent-release.update');
        Route::delete('/agent-release', [SuperadminAgentReleaseController::class, 'destroy'])->name('agent-release.destroy');

        Route::get('/clubs', [SuperadminClubController::class, 'index'])->name('clubs.index');
        Route::get('/clubs/{club}', [SuperadminClubController::class, 'show'])->name('clubs.show');
        Route::post('/clubs/{club}/subscription/extend', [SuperadminClubController::class, 'extend'])->name('clubs.extend');
        Route::post('/clubs/{club}/subscription/suspend', [SuperadminClubController::class, 'suspend'])->name('clubs.suspend');
        Route::post('/clubs/{club}/subscription/plan', [SuperadminClubController::class, 'changePlan'])->name('clubs.plan');
        Route::post('/clubs/{club}/view', [SuperadminClubController::class, 'view'])->name('clubs.view');
        Route::post('/leave-club', [SuperadminClubController::class, 'leave'])->name('clubs.leave');

        Route::get('/accounts', [SuperadminUserController::class, 'index'])->name('users.index');
        Route::post('/accounts/{user}/block', [SuperadminUserController::class, 'block'])->name('users.block');
        Route::post('/accounts/{user}/unblock', [SuperadminUserController::class, 'unblock'])->name('users.unblock');
        Route::post('/accounts/{user}/superadmin', [SuperadminUserController::class, 'grant'])->name('users.grant');
        Route::delete('/accounts/{user}/superadmin', [SuperadminUserController::class, 'revoke'])->name('users.revoke');

        Route::get('/plans', [SuperadminPlanController::class, 'index'])->name('plans.index');
        Route::post('/plans', [SuperadminPlanController::class, 'store'])->name('plans.store');
        Route::patch('/plans/{plan}', [SuperadminPlanController::class, 'update'])->name('plans.update');

        Route::get('/logs', SuperadminLogController::class)->name('logs.index');
        Route::get('/languages', [SuperadminLanguageController::class, 'index'])->name('languages');

        Route::get('/telegram', [SuperadminTelegramController::class, 'show'])->name('telegram.show');
        Route::post('/telegram', [SuperadminTelegramController::class, 'update'])->name('telegram.update');
        Route::delete('/telegram', [SuperadminTelegramController::class, 'destroy'])->name('telegram.destroy');

        Route::get('/platform-news', [SuperadminNewsController::class, 'index'])->name('news.index');
        Route::post('/platform-news', [SuperadminNewsController::class, 'store'])->name('news.store');
        Route::patch('/platform-news/{news}', [SuperadminNewsController::class, 'update'])->name('news.update');
        Route::delete('/platform-news/{news}', [SuperadminNewsController::class, 'destroy'])->name('news.destroy');
    });

    // Languages and translations shared by every club (route names kept from the club settings).
    Route::post('/languages', [SuperadminLanguageController::class, 'store'])->name('languages.store');
    Route::get('/languages/export', [SuperadminLanguageController::class, 'export'])->name('languages.export');
    Route::post('/languages/import', [SuperadminLanguageController::class, 'import'])->middleware('throttle:20,1,languages.import')->name('languages.import');
    Route::patch('/languages/{language}', [SuperadminLanguageController::class, 'update'])->name('languages.update');
    Route::delete('/languages/{language}', [SuperadminLanguageController::class, 'destroy'])->name('languages.destroy');
    Route::get('/languages/{language}/translations', [SuperadminLanguageController::class, 'translations'])->name('languages.translations');
    Route::put('/languages/{language}/translations', [SuperadminLanguageController::class, 'saveTranslations'])->name('languages.translations.save');
});

// Zafiro wiki: the whole documentation as one static page. On its own host (WIKI_DOMAIN) from the
// root, before the landing page's "/"; the old /wiki address of the site leads there.
$wiki = fn () => response()->file(resource_path('wiki/index.html'), ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'public, max-age=300']);
$wikiDomain = config('app.wiki_domain');
if ($wikiDomain === null) {
    Route::get('/wiki', $wiki)->name('wiki');
} else {
    Route::domain($wikiDomain)->get('/', $wiki)->name('wiki');
    Route::get('/wiki', fn () => redirect()->route('wiki', status: 301))->name('wiki.moved');
}

// Public routes
Route::get('/', LandingController::class)->name('home');

// Installable app: manifest and the page shown without a connection.
Route::get('/manifest.webmanifest', [PwaController::class, 'manifest'])->name('pwa.manifest');
Route::get('/offline', [PwaController::class, 'offline'])->name('pwa.offline');

// Integration API v1 reference (public: it describes the API, it contains no club data).
Route::view('/docs/api', 'api-docs')->name('api-docs');
Route::get('/docs/api/openapi.yaml', fn () => response()->file(resource_path('api/openapi-v1.yaml'), ['Content-Type' => 'application/yaml; charset=utf-8']))
    ->name('api-docs.spec');

// Club logo for the locked PC monitors: public by its random file name.
Route::get('/club-logos/{club}/{file}', [ClubLogoController::class, 'show'])
    ->where('file', '[A-Za-z0-9]{40}\\.(png|jpg)')
    ->name('club-logos.show');

// News pictures for the PC agent: public by their random file name.
Route::get('/news-images/{news}/{file}', [NewsController::class, 'image'])
    ->where('file', '[A-Za-z0-9]{40}\\.(png|jpg)')
    ->name('news-images.show');

Route::get('/invitations/{token}', [ClubInvitationController::class, 'show'])->name('club-invitations.show');
Route::post('/invitations/{token}', [ClubInvitationController::class, 'accept'])->middleware('throttle:10,1,club-invitations.accept')->name('club-invitations.accept');

// Sign-in on a PC from the phone (QR code on the PC): any club of the code, after the site sign-in.
Route::middleware('auth')->group(function () {
    Route::get('/pc-login/done', [QrLoginController::class, 'done'])->name('pc-login.done');
    Route::get('/pc-login/{code}', [QrLoginController::class, 'show'])->where('code', '[A-Za-z0-9]{40}')->name('pc-login.show');
    Route::post('/pc-login/{code}', [QrLoginController::class, 'approve'])->where('code', '[A-Za-z0-9]{40}')->middleware('throttle:10,1,pc-login.approve')->name('pc-login.approve');
});

// Auth routes
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('auth.show-login');
    Route::post('/login', [AuthController::class, 'login'])->name('auth.login');
    // Forgot password: a code to the account's phone in Telegram (Telegram Gateway), then a new password.
    Route::get('/forgot-password', [PasswordRecoveryController::class, 'show'])->name('auth.password.forgot');
    Route::post('/forgot-password', [PasswordRecoveryController::class, 'send'])->middleware('throttle:password-recovery')->name('auth.password.send');
    Route::get('/reset-password', [PasswordRecoveryController::class, 'edit'])->name('auth.password.reset');
    Route::post('/reset-password', [PasswordRecoveryController::class, 'update'])->middleware('throttle:password-recovery')->name('auth.password.update');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('auth.show-register');
    Route::post('/register', [AuthController::class, 'register'])->name('auth.register');
});

// Protected routes
Route::middleware(['auth', 'current-club'])->group(function () {
    Route::post('/clubs/switch', [ClubController::class, 'switch'])->name('clubs.switch');
    Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');
    // Staff only: customers sign in on the site just to confirm a PC sign-in (QR code).
    Route::middleware('club-role:owner,admin,manager')->get('/billing', [BillingController::class, 'show'])->name('billing.show');
    Route::middleware('club-role:owner,admin,manager,cashier,operator')->group(function () {
        Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::post('/notifications/seen', [NotificationController::class, 'markSeen'])->name('notifications.seen');
    });
    Route::middleware(['subscription', 'club-role:owner,admin,manager,cashier,operator'])->group(function () {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');
        Route::get('/todos', [TodoController::class, 'index'])->name('todos.index');
        Route::get('/todos/state', [TodoController::class, 'state'])->name('todos.state');
        Route::post('/todos/{task}/toggle', [TodoController::class, 'toggle'])->name('todos.toggle');
        Route::get('/chat', [ChatController::class, 'index'])->name('chat.index');
        Route::get('/chat/poll', [ChatController::class, 'poll'])->name('chat.poll');
        Route::post('/chat/{customer}', [ChatController::class, 'reply'])->middleware('throttle:60,1,chat.reply')->name('chat.reply');
        Route::get('/shop-orders', [ShopOrderController::class, 'index'])->name('shop-orders.index');
        Route::get('/shop-orders/state', [ShopOrderController::class, 'state'])->name('shop-orders.state');
        Route::post('/shop-orders/{order}/done', [ShopOrderController::class, 'done'])->name('shop-orders.done');
        Route::post('/shop-orders/{order}/cancel', [ShopOrderController::class, 'cancel'])->name('shop-orders.cancel');
    });

    Route::middleware(['subscription', 'club-role:owner,admin,manager'])->group(function () {
        Route::get('/warehouse', WarehouseController::class)->name('warehouse');
        Route::get('/activity-logs', [ActivityLogController::class, 'index'])->name('activity-logs.index');
        Route::post('/club-invitations', [ClubInvitationController::class, 'store'])->name('club-invitations.store');
        Route::resource('/club-map', ClubZoneController::class)->only(['store', 'update', 'destroy']);
        Route::delete('/club-computers/{clubComputer}', [ClubComputerController::class, 'destroy'])->name('club-computers.destroy');
        Route::patch('/club-map/{clubMap}/layout', [ClubZoneController::class, 'updateLayout'])->name('club-map.layout');
        Route::post('/club-map/{clubMap}/computers', [ClubComputerController::class, 'store'])->name('club-computers.store');
        Route::patch('/club-computers/{clubComputer}', [ClubComputerController::class, 'update'])->name('club-computers.update');
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
        Route::post('/settings/timezone', [SettingsController::class, 'timezone'])->name('settings.timezone');
        Route::post('/settings/logo', [ClubLogoController::class, 'update'])->name('settings.logo');
        Route::delete('/settings/logo', [ClubLogoController::class, 'destroy'])->name('settings.logo.destroy');
        Route::get('/tariffs', [TariffController::class, 'index'])->name('tariffs.index');
        Route::post('/todos', [TodoController::class, 'store'])->name('todos.store');
        Route::delete('/todos/{task}', [TodoController::class, 'destroy'])->name('todos.destroy');
        Route::get('/news', [NewsController::class, 'index'])->name('news.index');
        Route::post('/news', [NewsController::class, 'store'])->name('news.store');
        Route::patch('/news/{news}', [NewsController::class, 'update'])->name('news.update');
        Route::delete('/news/{news}', [NewsController::class, 'destroy'])->name('news.destroy');
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
        Route::post('/settings/agent-pin', [SettingsController::class, 'agentPin'])->name('settings.agent-pin');
        Route::post('/settings/agent-staff-time', [SettingsController::class, 'agentStaffTime'])->name('settings.agent-staff-time');
        Route::get('/workers', [WorkerController::class, 'index'])->name('workers.index');
        Route::post('/workers', [WorkerController::class, 'store'])->name('workers.store');
        Route::patch('/workers/{membership}', [WorkerController::class, 'update'])->name('workers.update');
        Route::delete('/workers/{membership}', [WorkerController::class, 'destroy'])->name('workers.destroy');
    });

    Route::middleware(['subscription', 'club-role:owner,admin,manager,cashier'])->group(function () {
        Route::get('/cash-register', [CashRegisterController::class, 'index'])->name('cash-register.index');
        // Time for a guest without an account, from the club map, paid into the open shift.
        Route::post('/club-computers/guest-session', [GuestSessionController::class, 'store'])->middleware('throttle:30,1,club-computers.guest-session')->name('club-computers.guest-session');
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

    // PC bookings: everyone who works at the front desk.
    Route::middleware(['subscription', 'club-role:owner,admin,manager,cashier,operator'])->group(function () {
        Route::get('/reservations', [ComputerReservationController::class, 'index'])->name('reservations.index');
        // Customers: the front desk registers them too (discount and balance only by managers).
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::patch('/users/{membership}', [UserController::class, 'update'])->name('users.update');
        Route::post('/users/{membership}/password', [UserController::class, 'resetPassword'])->middleware('throttle:20,1,users.password')->name('users.password');
        Route::post('/reservations', [ComputerReservationController::class, 'store'])->name('reservations.store');
        Route::post('/reservations/many', [ComputerReservationController::class, 'storeMany'])->name('reservations.store-many');
        Route::get('/reservations/customers', [ComputerReservationController::class, 'customers'])->middleware('throttle:60,1,reservations.customers')->name('reservations.customers');
        Route::post('/reservations/{reservation}/cancel', [ComputerReservationController::class, 'cancel'])->name('reservations.cancel');
        Route::post('/reservations/{reservation}/arrive', [ComputerReservationController::class, 'arrive'])->name('reservations.arrive');
    });

    // The front desk works with the PCs: the club map (no editor), the PC list, power and reserving.
    Route::middleware(['subscription', 'club-role:owner,admin,manager,cashier,operator'])->group(function () {
        Route::get('/club-map', [ClubZoneController::class, 'index'])->name('club-map.index');
        Route::get('/club-computers', ClubComputerListController::class)->name('club-computers.index');
        Route::post('/club-computers/control', [ClubComputerController::class, 'control'])->name('club-computers.control');
        // Stock for the front desk, read-only (the warehouse itself is for managers).
        Route::get('/stock', [StockController::class, 'index'])->name('stock.index');
        Route::post('/stock/receive', [StockController::class, 'receive'])->name('stock.receive');
    });
});
