<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Telegram;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Updates of the Zafiro bot; Telegram signs them with the secret set in setWebhook. */
class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, Telegram $telegram): JsonResponse
    {
        $secret = $telegram->webhookSecret();
        abort_unless($secret !== null && hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token')), 403);

        $telegram->handle($request->all());

        return response()->json(['ok' => true]);
    }
}
