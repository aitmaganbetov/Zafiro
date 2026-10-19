<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\PlatformLog;
use App\Services\Monitoring\TelegramAlerts;
use App\Services\PlatformLogger;
use App\Services\TelegramGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Telegram Gateway (password recovery codes) and the bot that sends failure alerts. */
class TelegramController extends Controller
{
    public function __construct(private TelegramGateway $gateway, private TelegramAlerts $alerts, private PlatformLogger $platformLog) {}

    public function show(): View
    {
        // Only the chat goes to the page, never the bot token.
        $alerts = $this->alerts->settings();

        return view('superadmin.telegram', [
            'configured' => $this->gateway->configured(),
            'alerts' => $alerts !== null ? ['chat_id' => $alerts['chat_id']] : null,
            'sentLastMonth' => PlatformLog::query()->where('event', 'user.password_code_sent')->where('created_at', '>=', now()->subDays(30))->count(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $token = $request->validate(['token' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_\-:.]{20,255}$/']], [
            'token.regex' => __('Это не похоже на токен Telegram Gateway: скопируйте его из настроек аккаунта gateway.telegram.org.'),
        ])['token'];

        try {
            $this->gateway->configure($token, $request->user());
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['token' => $exception->getMessage()]);
        }
        $this->platformLog->record('superadmin.telegram_gateway_set', __('Подключён Telegram Gateway'), $request->user());

        return back()->with('status', __('Telegram Gateway подключён.'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $this->gateway->disable($request->user());
        $this->platformLog->record('superadmin.telegram_gateway_cleared', __('Telegram Gateway отключён'), $request->user());

        return back()->with('status', __('Telegram Gateway отключён.'));
    }

    public function updateAlerts(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'bot_token' => ['required', 'string', 'max:255', 'regex:/^\d{5,15}:[A-Za-z0-9_\-]{30,100}$/'],
            'chat_id' => ['nullable', 'string', 'max:64', 'regex:/^(-?\d{1,20}|@[A-Za-z0-9_]{5,32})$/'],
        ], [
            'bot_token.regex' => __('Это не похоже на токен бота: скопируйте его из сообщения @BotFather.'),
            'chat_id.regex' => __('ID чата — число (у групп со знаком минус) или @имя канала.'),
        ]);

        try {
            $this->alerts->configure($data['bot_token'], $data['chat_id'] ?? null, app()->getLocale(), $request->user());
        } catch (\RuntimeException $exception) {
            return back()->withInput($request->only('chat_id'))->withErrors(['bot_token' => $exception->getMessage()]);
        }
        $this->platformLog->record('superadmin.telegram_alerts_set', __('Подключён бот оповещений о сбоях'), $request->user());

        return back()->with('status', __('Бот подключён: проверочное сообщение отправлено в чат.'));
    }

    public function testAlerts(): RedirectResponse
    {
        return $this->alerts->send(__('Проверка: оповещения Zafiro работают.'))
            ? back()->with('status', __('Сообщение отправлено.'))
            : back()->withErrors(['bot_token' => __('Не удалось отправить сообщение: проверьте бота и чат.')]);
    }

    public function destroyAlerts(Request $request): RedirectResponse
    {
        $this->alerts->disable($request->user());
        $this->platformLog->record('superadmin.telegram_alerts_cleared', __('Бот оповещений о сбоях отключён'), $request->user());

        return back()->with('status', __('Оповещения отключены.'));
    }
}
