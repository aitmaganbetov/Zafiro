<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Telegram Gateway (gatewayapi.telegram.org): verification codes sent to a phone number in
 * Telegram, with no bot to link. Used for password recovery. The server only connects out.
 * The access token is set in the superadmin cabinet, stored encrypted, never cached or logged.
 */
class TelegramGateway
{
    public const SETTING = 'telegram_gateway';

    private const URL = 'https://gatewayapi.telegram.org/';

    public function token(): ?string
    {
        // Straight from the table, not PlatformSetting::read(): that one caches values.
        $value = PlatformSetting::query()->find(self::SETTING)?->value;
        if (! is_array($value) || ! isset($value['token'])) {
            return null;
        }

        try {
            return Crypt::decryptString($value['token']);
        } catch (Throwable) {
            return null;
        }
    }

    public function configured(): bool
    {
        return $this->token() !== null;
    }

    /** Checks the token with Gateway (a free status request) and saves it. */
    public function configure(string $token, ?User $by): void
    {
        // An unknown request id with a valid token is REQUEST_ID_INVALID; with a bad one, ACCESS_TOKEN_INVALID.
        $response = $this->call($token, 'checkVerificationStatus', ['request_id' => '0']);
        if ($response === []) {
            throw new \RuntimeException(__('Не удалось связаться с Telegram Gateway. Попробуйте позже.'));
        }
        if (str_contains((string) ($response['error'] ?? ''), 'ACCESS_TOKEN')) {
            throw new \RuntimeException(__('Telegram Gateway не принял токен.'));
        }

        PlatformSetting::write(self::SETTING, ['token' => Crypt::encryptString($token)], $by);
    }

    public function disable(?User $by): void
    {
        PlatformSetting::write(self::SETTING, null, $by);
    }

    /** Sends our code (4–8 digits) to the number (E.164); Gateway refunds it if not delivered within $ttl seconds. */
    public function sendCode(string $phone, string $code, int $ttl): bool
    {
        $token = $this->token();
        if ($token === null) {
            return false;
        }
        $response = $this->call($token, 'sendVerificationMessage', ['phone_number' => $phone, 'code' => $code, 'ttl' => $ttl]);
        if (! ($response['ok'] ?? false)) {
            Log::warning('Telegram Gateway did not send a code', ['error' => (string) ($response['error'] ?? 'no response')]);
        }

        return ($response['ok'] ?? false) === true;
    }

    /** @return array<string, mixed> the response, [] when Gateway could not be reached */
    private function call(string $token, string $method, array $payload): array
    {
        try {
            $response = Http::withToken($token)->timeout(10)->asJson()->post(self::URL.$method, $payload);

            return is_array($response->json()) ? $response->json() : [];
        } catch (Throwable $exception) {
            // Neither the token nor the phone number goes to the log.
            Log::warning('Telegram Gateway request failed', ['method' => $method, 'error' => class_basename($exception)]);

            return [];
        }
    }
}
