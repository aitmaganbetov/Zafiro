<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\PlatformLog;
use App\Services\PlatformLogger;
use App\Services\TelegramGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Telegram Gateway: password recovery codes are sent through it. */
class TelegramController extends Controller
{
    public function __construct(private TelegramGateway $gateway, private PlatformLogger $platformLog) {}

    public function show(): View
    {
        return view('superadmin.telegram', [
            'configured' => $this->gateway->configured(),
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
}
