<?php

use App\Http\Controllers\Api\AgentEnrollController;
use App\Http\Controllers\Api\AgentExitPinController;
use App\Http\Controllers\Api\AgentQrLoginController;
use App\Http\Controllers\Api\AgentRegisterController;
use App\Http\Controllers\Api\AgentTariffController;
use App\Http\Controllers\Api\AgentTranslationController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClubInstallationController;
use App\Http\Controllers\Api\GuestChatController;
use App\Http\Controllers\Api\GuestFriendController;
use App\Http\Controllers\Api\GuestHistoryController;
use App\Http\Controllers\Api\GuestMinutesController;
use App\Http\Controllers\Api\GuestNewsController;
use App\Http\Controllers\Api\GuestPackageController;
use App\Http\Controllers\Api\GuestQrLoginController;
use App\Http\Controllers\Api\GuestSeatController;
use App\Http\Controllers\Api\GuestShopController;
use App\Http\Controllers\Api\GuestTelegramController;
use App\Http\Controllers\Api\PasswordRecoveryController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\ClubComputerController;
use App\Http\Controllers\SettingsController;
use App\Support\ClubRoles;
use Illuminate\Support\Facades\Route;

Route::post('/clubs/resolve', ClubInstallationController::class)
    ->middleware('throttle:club-resolve')
    ->name('api.clubs.resolve');

Route::get('/me', [AuthController::class, 'summary'])
    ->middleware(['auth:sanctum', 'current-club', 'subscription'])
    ->name('api.me');

Route::post('/agent/user/login', [AuthController::class, 'agentUserLogin'])
    ->middleware('throttle:customer-login')
    ->name('api.agent.user.login');

Route::prefix('auth')->name('api.auth.')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:customer-login')
        ->name('login');
    // Forgot password in the PC agent: a code to the phone in Telegram (Telegram Gateway).
    Route::post('/password/code', [PasswordRecoveryController::class, 'code'])
        ->middleware('throttle:password-recovery')
        ->name('password.code');
    Route::post('/password/reset', [PasswordRecoveryController::class, 'reset'])
        ->middleware('throttle:password-recovery')
        ->name('password.reset');

    Route::middleware(['auth:sanctum', 'current-club'])->group(function () {
        Route::middleware('subscription')
            ->get('/me', [AuthController::class, 'me'])
            ->name('me');
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        // The mobile app: the account's clubs and moving to another one (no subscription check:
        // a customer of an expired club can still move to an active one).
        Route::get('/clubs', [AuthController::class, 'clubs'])->middleware('throttle:30,1,api.auth.clubs')->name('clubs');
        Route::post('/switch-club', [AuthController::class, 'switchClub'])->middleware('throttle:10,1,api.auth.switch-club')->name('switch-club');
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
    ->middleware(['agent.device', 'throttle:agent-token-rotate'])
    ->name('api.agent.token.rotate');

// Agent texts with the superadmin's edits (public: the agent ships the same texts).
Route::get('/agent/translations/{locale}', AgentTranslationController::class)
    ->where('locale', '[a-z]{2,8}')
    ->middleware('throttle:agent-translations')
    ->name('api.agent.translations');

// Agent service of a diskless or cloned PC gets a token for its own PC (club key + hardware UUID).
Route::post('/agent/enroll', AgentEnrollController::class)
    ->middleware('throttle:agent-enroll')
    ->name('api.agent.enroll');

// Sign-in from the phone: the PC shows a QR code and asks whether the customer confirmed it.
Route::post('/agent/qr-login', [AgentQrLoginController::class, 'store'])
    ->middleware(['agent.device', 'throttle:agent-qr-login'])
    ->name('api.agent.qr-login.store');
Route::post('/agent/qr-login/poll', [AgentQrLoginController::class, 'poll'])
    ->middleware(['agent.device', 'throttle:agent-qr-poll'])
    ->name('api.agent.qr-login.poll');

// A customer registers on a club PC (sign-in window): a few per PC, then a pause.
Route::post('/agent/register', AgentRegisterController::class)
    ->middleware(['agent.device', 'throttle:agent-register'])
    ->name('api.agent.register');

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
    ->middleware(['auth:sanctum', 'current-club', 'subscription', 'throttle:60,1,api.products.index'])
    ->name('api.products.index');

Route::middleware(['auth:sanctum', 'current-club', 'subscription'])->group(function () {
    Route::get('/tariffs', [AgentTariffController::class, 'index'])
        ->middleware('throttle:60,1,api.tariffs.index')
        ->name('api.tariffs.index');
    Route::post('/tariffs/{tariff}/purchase', [AgentTariffController::class, 'purchase'])
        ->whereNumber('tariff')
        ->middleware('throttle:10,1,api.tariffs.purchase')
        ->name('api.tariffs.purchase');

    // Customer features in the PC agent, each switched on in the club's "Guests" settings.
    Route::get('/guest/friends', [GuestFriendController::class, 'index'])->middleware('throttle:60,1,api.guest.friends.index')->name('api.guest.friends.index');
    Route::post('/guest/friends', [GuestFriendController::class, 'store'])->middleware('throttle:10,1,api.guest.friends.store')->name('api.guest.friends.store');
    Route::post('/guest/friends/{friendship}/accept', [GuestFriendController::class, 'accept'])->middleware('throttle:30,1,api.guest.friends.accept')->name('api.guest.friends.accept');
    Route::post('/guest/friends/{friendship}/remove', [GuestFriendController::class, 'remove'])->middleware('throttle:30,1,api.guest.friends.remove')->name('api.guest.friends.remove');
    Route::post('/guest/friends/{friendship}/transfer', [GuestFriendController::class, 'transfer'])->middleware('throttle:10,1,api.guest.friends.transfer')->name('api.guest.friends.transfer');
    // Mobile app: the transfer is confirmed with a code from Telegram instead of the password.
    Route::post('/guest/friends/{friendship}/transfer/code', [GuestFriendController::class, 'transferCode'])->middleware('throttle:5,1,api.guest.friends.transfer.code')->name('api.guest.friends.transfer.code');
    Route::post('/guest/friends/{friendship}/transfer/confirm', [GuestFriendController::class, 'transferConfirm'])->middleware('throttle:10,1,api.guest.friends.transfer.confirm')->name('api.guest.friends.transfer.confirm');
    // Time by the minute at the zone's hourly price.
    Route::get('/guest/minutes', [GuestMinutesController::class, 'show'])->middleware('throttle:60,1,api.guest.minutes')->name('api.guest.minutes');
    Route::post('/guest/minutes/purchase', [GuestMinutesController::class, 'purchase'])->middleware('throttle:10,1,api.guest.minutes.purchase')->name('api.guest.minutes.purchase');
    // Kept time of a tariff with a usage limit, started again.
    Route::get('/guest/packages', [GuestPackageController::class, 'index'])->middleware('throttle:60,1,api.guest.packages')->name('api.guest.packages');
    Route::post('/guest/packages/{package}/start', [GuestPackageController::class, 'start'])->whereNumber('package')->middleware('throttle:10,1,api.guest.packages.start')->name('api.guest.packages.start');
    Route::get('/guest/chat', [GuestChatController::class, 'index'])->middleware('throttle:60,1,api.guest.chat')->name('api.guest.chat');
    Route::post('/guest/chat', [GuestChatController::class, 'store'])->middleware('throttle:20,1,api.guest.chat.store')->name('api.guest.chat.store');
    Route::get('/guest/telegram', [GuestTelegramController::class, 'show'])->middleware('throttle:30,1,api.guest.telegram')->name('api.guest.telegram');
    Route::get('/guest/news', GuestNewsController::class)->middleware('throttle:30,1,api.guest.news')->name('api.guest.news');
    Route::post('/guest/shop/purchase', [GuestShopController::class, 'purchase'])->middleware('throttle:10,1,api.guest.shop.purchase')->name('api.guest.shop.purchase');
    Route::get('/guest/history', GuestHistoryController::class)->middleware('throttle:60,1,api.guest.history')->name('api.guest.history');
    Route::get('/guest/seats', [GuestSeatController::class, 'index'])->middleware('throttle:30,1,api.guest.seats.index')->name('api.guest.seats.index');
    Route::post('/guest/seat-transfer', [GuestSeatController::class, 'store'])->middleware('throttle:10,1,api.guest.seat-transfer')->name('api.guest.seat-transfer');
    // The mobile app scans the PC's QR code and confirms the sign-in there (like the /pc-login page).
    Route::get('/guest/qr-login/{code}', [GuestQrLoginController::class, 'show'])->where('code', '[A-Za-z0-9]{40}')->middleware('throttle:30,1,api.guest.qr-login.show')->name('api.guest.qr-login.show');
    Route::post('/guest/qr-login/{code}', [GuestQrLoginController::class, 'approve'])->where('code', '[A-Za-z0-9]{40}')->middleware('throttle:10,1,api.guest.qr-login.approve')->name('api.guest.qr-login.approve');
});

Route::get('/settings/{section}/{key}', [SettingsController::class, 'showSetting'])
    ->where([
        'section' => '[a-z_]+',
        'key' => '[a-z_]+',
    ])
    ->middleware(['auth:sanctum', 'current-club', 'subscription', 'throttle:60,1,api.settings.show'])
    ->name('api.settings.show');

// Integration API for external systems: API keys bound to one club, see routes/api_v1.php.
// Staff mobile app (docs/staff-api.md): a token of one club, staff roles only.
Route::prefix('staff')
    ->name('api.staff.')
    ->middleware(['auth:sanctum', 'current-club', 'subscription', 'club-role:'.implode(',', ClubRoles::STAFF), 'throttle:staff-app'])
    ->group(base_path('routes/api_staff.php'));

// Separate prefix, so the routes above keep their contracts.
Route::prefix('v1')
    ->name('api.v1.')
    ->middleware(['api.log', 'api.size', 'api.key', 'api.limit'])
    ->group(base_path('routes/api_v1.php'));
