<?php

use App\Http\Controllers\Api\AgentTariffController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClubInstallationController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\ClubComputerController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::post('/clubs/resolve', ClubInstallationController::class)
    ->middleware('throttle:10,1')
    ->name('api.clubs.resolve');

Route::get('/me', [AuthController::class, 'summary'])
    ->middleware(['auth:sanctum', 'current-club', 'subscription'])
    ->name('api.me');

Route::post('/agent/user/login', [AuthController::class, 'agentUserLogin'])
    ->middleware('throttle:5,1')
    ->name('api.agent.user.login');

Route::prefix('auth')->name('api.auth.')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1')
        ->name('login');

    Route::middleware(['auth:sanctum', 'current-club'])->group(function () {
        Route::middleware('subscription')
            ->get('/me', [AuthController::class, 'me'])
            ->name('me');
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    });
});

Route::post('/club-computers/{computer}/heartbeat', [ClubComputerController::class, 'heartbeat'])
    ->middleware(['auth:sanctum', 'current-club', 'subscription', 'throttle:club-computers'])
    ->name('api.club-computers.heartbeat');

// Agent with a device token (issued to staff at registration): bound to one PC.
Route::post('/agent/heartbeat', [ClubComputerController::class, 'agentHeartbeat'])
    ->middleware(['agent.device', 'throttle:club-computers'])
    ->name('api.agent.heartbeat');

Route::post('/club-computers/{computer}/session/end', [ClubComputerController::class, 'endSession'])
    ->whereNumber('computer')
    ->middleware(['auth:sanctum', 'current-club', 'subscription', 'throttle:club-computers'])
    ->name('api.club-computers.session.end');

Route::post('/club-computers/resolve', [ClubComputerController::class, 'resolve'])
    ->middleware(['auth:sanctum', 'current-club', 'subscription', 'throttle:club-computers'])
    ->name('api.club-computers.resolve');

Route::post('/club-computers/register', [ClubComputerController::class, 'register'])
    ->middleware(['auth:sanctum', 'current-club', 'subscription', 'throttle:club-computer-registration'])
    ->name('api.club-computers.register');

Route::get('/products', ProductController::class)
    ->middleware(['auth:sanctum', 'current-club', 'subscription', 'throttle:60,1'])
    ->name('api.products.index');

Route::middleware(['auth:sanctum', 'current-club', 'subscription'])->group(function () {
    Route::get('/tariffs', [AgentTariffController::class, 'index'])
        ->middleware('throttle:60,1')
        ->name('api.tariffs.index');
    Route::post('/tariffs/{tariff}/purchase', [AgentTariffController::class, 'purchase'])
        ->whereNumber('tariff')
        ->middleware('throttle:10,1')
        ->name('api.tariffs.purchase');
});

Route::get('/settings/{section}/{key}', [SettingsController::class, 'showSetting'])
    ->where([
        'section' => '[a-z_]+',
        'key' => '[a-z_]+',
    ])
    ->middleware(['auth:sanctum', 'current-club', 'subscription', 'throttle:60,1'])
    ->name('api.settings.show');

// Integration API for external systems: API keys bound to one club, see routes/api_v1.php.
// Separate prefix, so the routes above keep their contracts.
Route::prefix('v1')
    ->name('api.v1.')
    ->middleware(['api.log', 'api.size', 'api.key', 'api.limit'])
    ->group(base_path('routes/api_v1.php'));
