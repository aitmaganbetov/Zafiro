<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Integrations\WebhookDispatcher;
use App\Services\Integrations\WebhookUrlGuard;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Sends one webhook delivery. Signature (see docs/integration-api.md):
 *   X-Zafiro-Signature: v1=hex(HMAC-SHA256(secret, "{timestamp}.{body}"))
 * with X-Zafiro-Timestamp and the unique X-Zafiro-Delivery id for replay protection.
 * Failed attempts are retried with the configured backoff; after the last one the
 * delivery is marked failed and the endpoint is disabled after too many in a row.
 * Runs outside any request, so it reads rows without the club scope by id only.
 */
class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 10; // retries are scheduled explicitly with release() and the backoff list

    public function __construct(public int $deliveryId) {}

    public function handle(WebhookUrlGuard $guard, WebhookDispatcher $dispatcher): void
    {
        $delivery = WebhookDelivery::query()->withoutGlobalScope('club')->find($this->deliveryId);

        if ($delivery === null || in_array($delivery->status, ['delivered', 'failed', 'skipped'], true)) {
            return;
        }

        $endpoint = WebhookEndpoint::query()->withoutGlobalScope('club')->find($delivery->webhook_endpoint_id);

        if ($endpoint === null || ! $endpoint->is_active) {
            $delivery->forceFill(['status' => 'skipped', 'next_attempt_at' => null])->save();

            return;
        }

        $attempt = $delivery->attempts + 1;
        [$status, $error] = $this->send($guard, $endpoint, $delivery);
        $delivery->attempts = $attempt;
        $delivery->last_status_code = $status;
        $delivery->last_error = $error === null ? null : mb_substr($error, 0, 255);

        if ($error === null) {
            $delivery->forceFill(['status' => 'delivered', 'delivered_at' => now(), 'next_attempt_at' => null])->save();
            $endpoint->forceFill(['failure_count' => 0, 'last_success_at' => now()])->save();

            return;
        }

        $backoff = (array) config('integrations.webhook_retry_backoff_seconds', []);

        if ($attempt <= count($backoff)) {
            $delay = (int) $backoff[$attempt - 1];
            $delivery->forceFill(['status' => 'pending', 'next_attempt_at' => now()->addSeconds($delay)])->save();
            $this->release($delay);

            return;
        }

        $delivery->forceFill(['status' => 'failed', 'next_attempt_at' => null])->save();
        $endpoint->failure_count++;
        $endpoint->last_failure_at = now();

        if ($endpoint->failure_count >= (int) config('integrations.webhook_disable_after_failures', 20)) {
            $endpoint->is_active = false;
            $dispatcher->forgetClub($endpoint->club_id);
        }

        $endpoint->save();
    }

    /**
     * The body is signed exactly as sent; the timestamp is part of the signed string.
     *
     * @return array{0: int|null, 1: string|null} HTTP status and error (null on success)
     */
    private function send(WebhookUrlGuard $guard, WebhookEndpoint $endpoint, WebhookDelivery $delivery): array
    {
        $target = $guard->check($endpoint->url);

        if (is_string($target)) {
            return [null, "blocked: {$target}"];
        }

        $body = json_encode($delivery->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->getTimestamp();

        try {
            $response = Http::timeout((int) config('integrations.webhook_timeout_seconds', 5))
                ->connectTimeout(3)
                ->withoutRedirecting()
                ->withOptions(['curl' => [CURLOPT_RESOLVE => ["{$target['host']}:{$target['port']}:".(str_contains($target['ip'], ':') ? "[{$target['ip']}]" : $target['ip'])]]])
                ->withHeaders([
                    'User-Agent' => 'Zafiro-Webhooks/1',
                    'X-Zafiro-Event' => $delivery->event,
                    'X-Zafiro-Delivery' => $delivery->event_id,
                    'X-Zafiro-Timestamp' => $timestamp,
                    'X-Zafiro-Signature' => 'v1='.hash_hmac('sha256', "{$timestamp}.{$body}", $endpoint->secret),
                ])
                ->withBody($body, 'application/json')
                ->post($endpoint->url);
        } catch (Throwable $e) {
            return [null, 'connection failed: '.class_basename($e)];
        }

        return $response->successful() ? [$response->status(), null] : [$response->status(), "HTTP {$response->status()}"];
    }
}
