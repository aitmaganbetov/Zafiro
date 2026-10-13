<?php

namespace App\Services\Integrations;

use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\CurrentClub;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Queues a delivery for every active endpoint of the current club that subscribed
 * to the event. Deliveries are rows of the surrounding transaction and the jobs are
 * dispatched after commit, so a rolled-back operation never notifies anyone.
 * Which endpoints listen is cached per club, so clubs without webhooks pay one
 * cache read per event.
 */
class WebhookDispatcher
{
    public function __construct(private CurrentClub $currentClub) {}

    /** @param  array<string, mixed>  $data  no secrets or personal data */
    public function dispatch(string $event, array $data): void
    {
        $clubId = $this->currentClub->id();

        if ($clubId === null) {
            return;
        }

        foreach ($this->listeners($clubId) as $endpoint) {
            if (! in_array($event, $endpoint['events'], true)) {
                continue;
            }

            $eventId = (string) Str::uuid();
            $delivery = (new WebhookDelivery)->forceFill([
                'club_id' => $clubId,
                'webhook_endpoint_id' => $endpoint['id'],
                'event_id' => $eventId,
                'event' => $event,
                'payload' => ['id' => $eventId, 'event' => $event, 'created_at' => now()->toIso8601String(), 'club_id' => $clubId, 'data' => $data],
                'status' => 'pending',
                'next_attempt_at' => now(),
            ]);
            $delivery->save();

            DeliverWebhook::dispatch($delivery->id)->afterCommit();
        }
    }

    public function forgetClub(int $clubId): void
    {
        Cache::forget("webhooks.listeners.{$clubId}");
    }

    /** @return list<array{id: int, events: list<string>}> */
    private function listeners(int $clubId): array
    {
        return Cache::remember("webhooks.listeners.{$clubId}", 600, fn (): array => WebhookEndpoint::query()
            ->withoutGlobalScope('club')
            ->where('club_id', $clubId)
            ->where('is_active', true)
            ->get(['id', 'events'])
            ->map(fn (WebhookEndpoint $endpoint): array => ['id' => $endpoint->id, 'events' => $endpoint->events])
            ->all());
    }
}
