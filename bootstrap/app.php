<?php

use App\Http\Middleware\Api\ApiIdempotency;
use App\Http\Middleware\Api\AuthenticateApiKey;
use App\Http\Middleware\Api\LimitApiRequestSize;
use App\Http\Middleware\Api\LogApiRequest;
use App\Http\Middleware\Api\RequireApiScope;
use App\Http\Middleware\RequireActiveSubscription;
use App\Http\Middleware\RequireClubRole;
use App\Http\Middleware\SetCurrentClub;
use App\Http\Middleware\SetLocale;
use App\Support\ApiError;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn (Request $request) => route('auth.show-login'));
        $middleware->alias([
            'current-club' => SetCurrentClub::class,
            'club-role' => RequireClubRole::class,
            'subscription' => RequireActiveSubscription::class,
            'api.log' => LogApiRequest::class,
            'api.size' => LimitApiRequestSize::class,
            'api.key' => AuthenticateApiKey::class,
            'api.scope' => RequireApiScope::class,
            'api.idempotent' => ApiIdempotency::class,
        ]);
        $middleware->appendToPriorityList(AuthenticatesRequests::class, SetCurrentClub::class);
        // /api/v1: log everything, check size and the key (which sets the club) before
        // rate limiting and route model binding, so bindings are scoped to the key's club.
        $middleware->prependToPriorityList(ThrottleRequests::class, LogApiRequest::class);
        $middleware->prependToPriorityList(ThrottleRequests::class, LimitApiRequestSize::class);
        $middleware->prependToPriorityList(ThrottleRequests::class, AuthenticateApiKey::class);
        $middleware->web(append: [SetLocale::class]);
        $middleware->api(append: [SetLocale::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
        $exceptions->render(fn (Throwable $e, Request $request) => $request->is('api/v1', 'api/v1/*') ? ApiError::fromThrowable($e) : null);
    })->create();
