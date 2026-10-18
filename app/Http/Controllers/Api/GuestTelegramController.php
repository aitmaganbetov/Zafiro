<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Telegram in the profile of agents before 0.0.57 (the Zafiro bot, now removed): they ask here
 * and hide the section when it is not available. Passwords are recovered with Telegram Gateway.
 */
class GuestTelegramController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['available' => false, 'linked' => false]);
    }
}
