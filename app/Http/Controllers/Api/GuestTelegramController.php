<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Telegram;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The customer links Telegram in the PC agent: a new password after a reset is sent there. */
class GuestTelegramController extends Controller
{
    public function __construct(private Telegram $telegram) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->state($request));
    }

    /** A one-time t.me link (15 minutes) for the agent to show as a QR code. */
    public function link(Request $request): JsonResponse
    {
        $url = $this->telegram->linkUrl($request->user());
        abort_if($url === null, 409, __('Telegram-бот Zafiro пока не подключён.'));

        return response()->json([...$this->state($request), 'url' => $url, 'expires_in_minutes' => Telegram::LINK_MINUTES]);
    }

    public function unlink(Request $request): JsonResponse
    {
        $request->user()->forceFill(['telegram_chat_id' => null, 'telegram_linked_at' => null])->save();

        return response()->json($this->state($request));
    }

    /** @return array{available: bool, linked: bool} */
    private function state(Request $request): array
    {
        return ['available' => $this->telegram->configured(), 'linked' => $request->user()->telegram_chat_id !== null];
    }
}
