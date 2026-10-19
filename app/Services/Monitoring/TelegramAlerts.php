<?php

namespace App\Services\Monitoring;

use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Failure alerts to a Telegram chat through a bot (Bot API). Telegram Gateway only sends
 * verification codes, so alerts need a bot of their own. The bot token is set in the cabinet,
 * stored encrypted, never cached or logged.
 */
class TelegramAlerts
{
    public const SETTING = 'telegram_alerts';

    /** @return array{token: string, chat_id: string, locale: string}|null */
    public function settings(): ?array
    {
        // Straight from the table, not PlatformSetting::read(): that one caches values.
        $value = PlatformSetting::query()->find(self::SETTING)?->value;
        if (! is_array($value) || ! isset($value['token'], $value['chat_id'])) {
            return null;
        }

        try {
            return ['token' => Crypt::decryptString($value['token']), 'chat_id' => (string) $value['chat_id'], 'locale' => (string) ($value['locale'] ?? config('app.locale'))];
        } catch (Throwable) {
            return null;
        }
    }

    public function configured(): bool
    {
        return $this->settings() !== null;
    }

    /** Language of the alerts: the one of the superadmin who connected the bot. */
    public function locale(): string
    {
        return $this->settings()['locale'] ?? config('app.locale');
    }

    /**
     * Checks the token, finds the chat when none is given (the last one that wrote to the bot),
     * sends a test message and saves the settings.
     */
    public function configure(string $token, ?string $chatId, string $locale, ?User $by): void
    {
        $me = $this->call($token, 'getMe');
        if ($me === []) {
            throw new \RuntimeException(__('Не удалось связаться с Telegram. Попробуйте позже.'));
        }
        if (! ($me['ok'] ?? false)) {
            throw new \RuntimeException(__('Telegram не принял токен бота.'));
        }

        if ($chatId === null) {
            $updates = $this->call($token, 'getUpdates');
            $last = collect($updates['result'] ?? [])->map(fn ($update) => $update['message']['chat']['id'] ?? $update['my_chat_member']['chat']['id'] ?? null)->filter()->last();
            if ($last === null) {
                throw new \RuntimeException(__('Бот пока никому не писал: отправьте боту /start (или добавьте его в группу) и сохраните ещё раз.'));
            }
            $chatId = (string) $last;
        }

        if (! $this->deliver($token, $chatId, __('Оповещения Zafiro подключены: сюда будут приходить сообщения о сбоях сервера.'))) {
            throw new \RuntimeException(__('Не удалось отправить сообщение в чат :chat. Проверьте, что бот добавлен в чат.', ['chat' => $chatId]));
        }

        PlatformSetting::write(self::SETTING, ['token' => Crypt::encryptString($token), 'chat_id' => $chatId, 'locale' => $locale], $by);
    }

    public function disable(?User $by): void
    {
        PlatformSetting::write(self::SETTING, null, $by);
    }

    public function send(string $text): bool
    {
        $settings = $this->settings();

        return $settings !== null && $this->deliver($settings['token'], $settings['chat_id'], $text);
    }

    private function deliver(string $token, string $chatId, string $text): bool
    {
        $response = $this->call($token, 'sendMessage', ['chat_id' => $chatId, 'text' => $text, 'disable_web_page_preview' => true]);
        if (! ($response['ok'] ?? false)) {
            Log::warning('Telegram alert was not sent', ['error' => (string) ($response['description'] ?? 'no response')]);
        }

        return ($response['ok'] ?? false) === true;
    }

    /** @return array<string, mixed> the response, [] when Telegram could not be reached */
    private function call(string $token, string $method, array $payload = []): array
    {
        try {
            // The token is part of the URL: exceptions are logged by class only.
            $response = Http::timeout(10)->asJson()->post('https://api.telegram.org/bot'.$token.'/'.$method, $payload);

            return is_array($response->json()) ? $response->json() : [];
        } catch (Throwable $exception) {
            Log::warning('Telegram request failed', ['method' => $method, 'error' => class_basename($exception)]);

            return [];
        }
    }
}
