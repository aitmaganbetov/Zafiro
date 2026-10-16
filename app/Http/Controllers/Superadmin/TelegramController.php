<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PlatformLogger;
use App\Services\Telegram;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The Zafiro Telegram bot: new passwords of customers are sent through it. */
class TelegramController extends Controller
{
    public function __construct(private Telegram $telegram, private PlatformLogger $platformLog) {}

    public function show(): View
    {
        return view('superadmin.telegram', [
            'bot' => $this->telegram->bot()['username'] ?? null,
            'linked' => User::query()->whereNotNull('telegram_chat_id')->count(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $token = $request->validate(['token' => ['required', 'string', 'max:100', 'regex:/^\d{5,15}:[A-Za-z0-9_-]{30,60}$/']], [
            'token.regex' => __('Токен бота выглядит так: 123456789:ABC… — его выдаёт @BotFather.'),
        ])['token'];

        try {
            $username = $this->telegram->configure($token, $request->user());
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['token' => $exception->getMessage()]);
        }
        $this->platformLog->record('superadmin.telegram_set', __('Подключён Telegram-бот @:bot', ['bot' => $username]), $request->user());

        return back()->with('status', __('Бот @:bot подключён.', ['bot' => $username]));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $this->telegram->disable($request->user());
        $this->platformLog->record('superadmin.telegram_cleared', __('Telegram-бот отключён'), $request->user());

        return back()->with('status', __('Бот отключён.'));
    }
}
