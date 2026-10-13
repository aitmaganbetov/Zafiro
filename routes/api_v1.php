<?php

/*
 * Integration API v1 for club systems (key auth, see docs/integration-api.md).
 * Every route must declare api.scope:<scope> (or api.scope:any); routes without it
 * are rejected by AuthenticateApiKey. The club always comes from the API key.
 */

use App\Http\Controllers\Api\V1\MeController;
use Illuminate\Support\Facades\Route;

Route::get('/me', MeController::class)->middleware('api.scope:any')->name('me');
