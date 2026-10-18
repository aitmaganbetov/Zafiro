<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The Zafiro Telegram bot (one for the platform, set up in the superadmin cabinet). A customer
 * links Telegram from the PC agent (t.me/<bot>?start=<one-time code>); then a new password
 * after a reset is sent there. The bot token is stored encrypted and is never cached or logged.
 *
 * Messages to the bot are taken by long polling (telegram:poll, every minute): the server only
 * connects out to Telegram, so it works where Telegram cannot reach the server (no webhook).
 */
class Telegram
{
    public const SETTING = 'telegram_bot';

    public const LINK_MINUTES = 15;

    /** The next update to ask Telegram for (not a secret). */
    public const OFFSET_CACHE = 'telegram:update-offset';

    /** @return array{token: string, username: string}|null */
    public function bot(): ?array
    {
        // Straight from the table, not PlatformSetting::read(): that one caches values.
        $value = PlatformSetting::query()->find(self::SETTING)?->value;
        if (! is_array($value) || ! isset($value['token'], $value['username'])) {
            return null;
        }

        try {
            return ['token' => Crypt::decryptString($value['token']), 'username' => (string) $value['username']];
        } catch (Throwable) {
            return null;
        }
    }

    public function configured(): bool
    {
        return $this->bot() !== null;
    }

    /** Checks the token with Telegram and saves it; messages are then polled. Returns the bot username. */
    public function configure(string $token, ?User $by): string
    {
        $me = $this->call($token, 'getMe');
        $username = $me['result']['username'] ?? null;
        if (! ($me['ok'] ?? false) || ! is_string($username)) {
            throw new \RuntimeException(__('Telegram не принял токен бота.'));
        }

        // Updates are polled: a webhook would make Telegram refuse getUpdates.
        $this->call($token, 'deleteWebhook');
        Cache::forget(self::OFFSET_CACHE);

        PlatformSetting::write(self::SETTING, ['token' => Crypt::encryptString($token), 'username' => $username], $by);

        return $username;
    }

    public function disable(?User $by): void
    {
        if ($bot = $this->bot()) {
            $this->call($bot['token'], 'deleteWebhook');
        }
        PlatformSetting::write(self::SETTING, null, $by);
    }

    /**
     * Takes the bot's new messages from Telegram and handles them, waiting for new ones (long
     * polling) for up to $seconds. Returns how many were handled.
     */
    public function poll(int $seconds): int
    {
        $bot = $this->bot();
        $handled = 0;
        $webhookRemoved = false;
        $until = time() + $seconds;
        while ($bot !== null) {
            $wait = max(0, min(25, $until - time()));
            $response = $this->call($bot['token'], 'getUpdates', [
                'offset' => (int) Cache::get(self::OFFSET_CACHE, 0),
                'timeout' => $wait,
                'allowed_updates' => ['message'],
            ], $wait + 10);

            // A webhook is still set (a bot saved before polling): remove it and ask again.
            if (($response['error_code'] ?? null) === 409 && ! $webhookRemoved) {
                $this->call($bot['token'], 'deleteWebhook');
                $webhookRemoved = true;

                continue;
            }
            if (! ($response['ok'] ?? false)) {
                break;
            }

            foreach ($response['result'] ?? [] as $update) {
                // Moved on first: a message that fails is not taken again and again.
                Cache::forever(self::OFFSET_CACHE, (int) ($update['update_id'] ?? 0) + 1);
                $this->handle(is_array($update) ? $update : []);
                $handled++;
            }
            if ($wait === 0 || time() >= $until) {
                break;
            }
        }

        return $handled;
    }

    /** A fresh one-time link for the customer to open in Telegram. */
    public function linkUrl(User $user): ?string
    {
        $bot = $this->bot();
        if ($bot === null) {
            return null;
        }

        $code = Str::random(32);
        $user->forceFill(['telegram_link_hash' => hash('sha256', $code), 'telegram_link_expires_at' => now()->addMinutes(self::LINK_MINUTES)])->save();

        return 'https://t.me/'.$bot['username'].'?start='.$code;
    }

    /** An update from Telegram: /start <code> links the chat to the customer, /stop unlinks it. */
    public function handle(array $update): void
    {
        $chatId = $update['message']['chat']['id'] ?? null;
        $text = trim((string) ($update['message']['text'] ?? ''));
        if (! is_int($chatId) && ! is_string($chatId)) {
            return;
        }
        $chatId = (string) $chatId;

        if (preg_match('/^\/start\s+([A-Za-z0-9]{32})$/', $text, $match)) {
            $user = User::query()->where('telegram_link_hash', hash('sha256', $match[1]))
                ->where('telegram_link_expires_at', '>', now())->first();
            if ($user === null) {
                $this->send($chatId, __('Ссылка устарела. Откройте её заново в агенте на ПК клуба.'));

                return;
            }

            // One chat belongs to one account.
            User::query()->where('telegram_chat_id', $chatId)->whereKeyNot($user->getKey())
                ->update(['telegram_chat_id' => null, 'telegram_linked_at' => null]);
            $user->forceFill(['telegram_chat_id' => $chatId, 'telegram_linked_at' => now(), 'telegram_link_hash' => null, 'telegram_link_expires_at' => null])->save();
            $this->send($chatId, __('Telegram привязан к аккаунту Zafiro :name. Сюда придёт новый пароль, если его сбросят в клубе. Отвязать: /stop', ['name' => $user->name]));

            return;
        }

        if ($text === '/stop') {
            User::query()->where('telegram_chat_id', $chatId)->update(['telegram_chat_id' => null, 'telegram_linked_at' => null]);
            $this->send($chatId, __('Telegram отвязан от аккаунта Zafiro.'));

            return;
        }

        $this->send($chatId, __('Чтобы привязать Telegram, откройте ссылку из агента на ПК клуба (Профиль → Telegram).'));
    }

    public function send(string $chatId, string $text): bool
    {
        $bot = $this->bot();

        return $bot !== null && ($this->call($bot['token'], 'sendMessage', ['chat_id' => $chatId, 'text' => $text])['ok'] ?? false) === true;
    }

    /** @return array<string, mixed> */
    private function call(string $token, string $method, array $payload = [], int $timeout = 10): array
    {
        try {
            $response = Http::timeout($timeout)->asJson()->post("https://api.telegram.org/bot{$token}/{$method}", $payload);

            return is_array($response->json()) ? $response->json() : [];
        } catch (Throwable $exception) {
            // The URL holds the token: log only the method.
            Log::warning('Telegram request failed', ['method' => $method, 'error' => class_basename($exception)]);

            return [];
        }
    }
}
