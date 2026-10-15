<?php

use App\Http\Controllers\Api\AgentEnrollController;
use App\Http\Controllers\Api\AgentExitPinController;
use App\Http\Controllers\Api\AgentQrLoginController;
use App\Http\Controllers\Api\AgentTariffController;
use App\Http\Controllers\Api\AgentTranslationController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClubInstallationController;
use App\Http\Controllers\Api\GuestFriendController;
use App\Http\Controllers\Api\GuestHistoryController;
use App\Http\Controllers\Api\GuestSeatController;
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

// The Windows service swaps a token that passed through the customer's profile for a new one.
Route::post('/agent/token/rotate', [ClubComputerController::class, 'rotateAgentToken'])
    ->middleware(['agent.device', 'throttle:10,1'])
    ->name('api.agent.token.rotate');

// Agent texts with the superadmin's edits (public: the agent ships the same texts).
Route::get('/agent/translations/{locale}', AgentTranslationController::class)
    ->where('locale', '[a-z]{2,8}')
    ->middleware('throttle:120,1')
    ->name('api.agent.translations');

// Agent service of a diskless or cloned PC gets a token for its own PC (club key + hardware UUID).
Route::post('/agent/enroll', AgentEnrollController::class)
    ->middleware('throttle:agent-enroll')
    ->name('api.agent.enroll');

// Sign-in from the phone: the PC shows a QR code and asks whether the customer confirmed it.
Route::post('/agent/qr-login', [AgentQrLoginController::class, 'store'])
    ->middleware(['agent.device', 'throttle:20,1'])
    ->name('api.agent.qr-login.store');
Route::post('/agent/qr-login/poll', [AgentQrLoginController::class, 'poll'])
    ->middleware(['agent.device', 'throttle:60,1'])
    ->name('api.agent.qr-login.poll');

Route::post('/agent/exit-pin', AgentExitPinController::class)
    ->middleware(['agent.device', 'throttle:agent-exit-pin'])
    ->name('api.agent.exit-pin');

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

    // Customer features in the PC agent, each switched on in the club's "Guests" settings.
    Route::get('/guest/friends', [GuestFriendController::class, 'index'])->middleware('throttle:60,1')->name('api.guest.friends.index');
    Route::post('/guest/friends', [GuestFriendController::class, 'store'])->middleware('throttle:10,1')->name('api.guest.friends.store');
    Route::post('/guest/friends/{friendship}/accept', [GuestFriendController::class, 'accept'])->middleware('throttle:30,1')->name('api.guest.friends.accept');
    Route::post('/guest/friends/{friendship}/remove', [GuestFriendController::class, 'remove'])->middleware('throttle:30,1')->name('api.guest.friends.remove');
    Route::post('/guest/friends/{friendship}/transfer', [GuestFriendController::class, 'transfer'])->middleware('throttle:10,1')->name('api.guest.friends.transfer');
    Route::get('/guest/history', GuestHistoryController::class)->middleware('throttle:60,1')->name('api.guest.history');
    Route::get('/guest/seats', [GuestSeatController::class, 'index'])->middleware('throttle:30,1')->name('api.guest.seats.index');
    Route::post('/guest/seat-transfer', [GuestSeatController::class, 'store'])->middleware('throttle:10,1')->name('api.guest.seat-transfer');
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
