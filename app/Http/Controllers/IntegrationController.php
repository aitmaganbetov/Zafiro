<?php

namespace App\Http\Controllers;

use App\Jobs\DeliverWebhook;
use App\Models\ApiKey;
use App\Models\ApiRequestLog;
use App\Models\ClubMembership;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\ActivityLogger;
use App\Services\CurrentClub;
use App\Services\Integrations\ApiKeyManager;
use App\Services\Integrations\WebhookUrlGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Settings → API and integrations: API keys, webhooks, who may manage them and the
 * recent API calls of the club. Open to the owner and members with the
 * integrations permission. Secrets are shown once in the response that created
 * them and are never put into the session.
 */
class IntegrationController extends Controller
{
    private const MAX_ACTIVE_KEYS = 50;

    private const MAX_WEBHOOKS = 20;

    public function __construct(
        private CurrentClub $currentClub,
        private ApiKeyManager $keys,
        private ActivityLogger $activity,
    ) {}

    public function index(): Response
    {
        return $this->page();
    }

    /** @param  array{type?: string, name?: string, secret?: string}  $created  a secret to show once */
    private function page(array $created = []): Response
    {
        $clubId = $this->currentClub->id();
        $view = view('settings.integrations', [
            'keys' => ApiKey::query()->with('creator:id,name')->latest('id')->get(),
            'webhooks' => WebhookEndpoint::query()->latest('id')->get(),
            'deliveries' => WebhookDelivery::query()->with('endpoint:id,url')->latest('id')->limit(20)->get(),
            'requests' => ApiRequestLog::query()->where('club_id', $clubId)->latest('created_at')->limit(30)->get(),
            'staff' => ClubMembership::query()->with('user:id,name')->whereIn('role', ['admin', 'manager', 'cashier', 'operator'])->orderBy('role')->get(),
            'scopes' => config('integrations.scopes'),
            'events' => config('integrations.webhook_events'),
            'isOwner' => $this->currentClub->membership()?->role === 'owner',
            'created' => $created,
        ]);

        // A page that shows a new secret must not be stored by the browser or proxies.
        return response($view)->header('Cache-Control', 'no-store, private');
    }

    public function storeKey(Request $request): Response|RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['required', 'string', Rule::in(array_keys(config('integrations.scopes')))],
            'allowed_ips' => ['nullable', 'string', 'max:2000'],
            'rate_limit_per_minute' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('integrations.max_rate_limit_per_minute')],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ]);
        $data['allowed_ips'] = $this->parseIps($data['allowed_ips'] ?? null);

        if (ApiKey::query()->whereNull('revoked_at')->count() >= self::MAX_ACTIVE_KEYS) {
            throw ValidationException::withMessages(['name' => __('Слишком много активных ключей. Отзовите неиспользуемые.')]);
        }

        [$key, $plain] = DB::transaction(function () use ($data, $request): array {
            $result = $this->keys->create($request->user(), $data);
            $this->activity->record('api_key.created', __('Создан API-ключ: :name (:prefix)', ['name' => $result[0]->name, 'prefix' => $result[0]->prefix]), $request->user()->getKey(), $result[0], [
                'scopes' => implode(', ', $result[0]->scopes),
                'allowed_ips' => implode(', ', $result[0]->allowed_ips ?? []) ?: '—',
                'expires_at' => $result[0]->expires_at?->toDateString() ?? '—',
            ]);

            return $result;
        });

        return $this->page(['type' => 'key', 'name' => $key->name, 'secret' => $plain]);
    }

    public function rotateKey(Request $request, ApiKey $apiKey): Response
    {
        abort_if($apiKey->revoked_at !== null, 404);

        [$key, $plain] = DB::transaction(function () use ($apiKey, $request): array {
            $result = $this->keys->rotate($apiKey, $request->user());
            $this->activity->record('api_key.rotated', __('API-ключ перевыпущен: :name (:old → :new)', ['name' => $apiKey->name, 'old' => $apiKey->prefix, 'new' => $result[0]->prefix]), $request->user()->getKey(), $result[0]);

            return $result;
        });

        return $this->page(['type' => 'key', 'name' => $key->name, 'secret' => $plain]);
    }

    public function revokeKey(Request $request, ApiKey $apiKey): RedirectResponse
    {
        if ($apiKey->revoked_at === null) {
            DB::transaction(function () use ($apiKey, $request): void {
                $this->keys->revoke($apiKey, $request->user());
                $this->activity->record('api_key.revoked', __('API-ключ отозван: :name (:prefix)', ['name' => $apiKey->name, 'prefix' => $apiKey->prefix]), $request->user()->getKey(), $apiKey);
            });
        }

        return redirect()->route('settings.integrations')->with('status', __('Ключ отозван. Запросы с ним больше не принимаются.'));
    }

    public function storeWebhook(Request $request, WebhookUrlGuard $guard): Response
    {
        $data = $this->validateWebhook($request, $guard);

        if (WebhookEndpoint::query()->count() >= self::MAX_WEBHOOKS) {
            throw ValidationException::withMessages(['url' => __('Достигнуто максимальное число вебхуков.')]);
        }

        $secret = 'whsec_'.Str::random(40);
        $endpoint = DB::transaction(function () use ($data, $secret, $request): WebhookEndpoint {
            $endpoint = (new WebhookEndpoint([...$data, 'is_active' => true]))->forceFill(['secret' => $secret, 'created_by' => $request->user()->getKey()]);
            $endpoint->save();
            $this->activity->record('webhook.created', __('Добавлен вебхук: :url', ['url' => $endpoint->url]), $request->user()->getKey(), $endpoint, ['events' => implode(', ', $endpoint->events)]);

            return $endpoint;
        });

        return $this->page(['type' => 'webhook', 'name' => $endpoint->url, 'secret' => $secret]);
    }

    public function updateWebhook(Request $request, WebhookEndpoint $webhook, WebhookUrlGuard $guard): RedirectResponse
    {
        $data = $request->has('url') ? $this->validateWebhook($request, $guard) : [];
        $data += $request->validate(['is_active' => ['sometimes', 'boolean']]);

        DB::transaction(function () use ($webhook, $data, $request): void {
            $webhook->fill($data);

            if ($webhook->isDirty('is_active') && $webhook->is_active) {
                $webhook->failure_count = 0;
            }

            $changes = collect($webhook->getDirty())->only(['url', 'events', 'is_active'])
                ->map(fn (mixed $value): string => is_array($value) ? implode(', ', $value) : (string) json_encode($value))->all();
            $webhook->save();

            if ($changes !== []) {
                $this->activity->record('webhook.updated', __('Изменён вебхук: :url', ['url' => $webhook->url]), $request->user()->getKey(), $webhook, $changes);
            }
        });

        return redirect()->route('settings.integrations')->with('status', __('Вебхук сохранён.'));
    }

    public function rotateWebhookSecret(Request $request, WebhookEndpoint $webhook): Response
    {
        $secret = 'whsec_'.Str::random(40);

        DB::transaction(function () use ($webhook, $secret, $request): void {
            $webhook->forceFill(['secret' => $secret])->save();
            $this->activity->record('webhook.secret_rotated', __('Перевыпущен секрет вебхука: :url', ['url' => $webhook->url]), $request->user()->getKey(), $webhook);
        });

        return $this->page(['type' => 'webhook', 'name' => $webhook->url, 'secret' => $secret]);
    }

    public function destroyWebhook(Request $request, WebhookEndpoint $webhook): RedirectResponse
    {
        DB::transaction(function () use ($webhook, $request): void {
            $webhook->delete();
            $this->activity->record('webhook.deleted', __('Удалён вебхук: :url', ['url' => $webhook->url]), $request->user()->getKey(), $webhook);
        });

        return redirect()->route('settings.integrations')->with('status', __('Вебхук удалён.'));
    }

    public function retryDelivery(Request $request, WebhookDelivery $delivery): RedirectResponse
    {
        abort_unless(in_array($delivery->status, ['failed', 'skipped'], true), 404);

        DB::transaction(function () use ($delivery, $request): void {
            $delivery->forceFill(['status' => 'pending', 'next_attempt_at' => now()])->save();
            $this->activity->record('webhook.retried', __('Повторная отправка вебхука: :event', ['event' => $delivery->event]), $request->user()->getKey(), $delivery);
            DeliverWebhook::dispatch($delivery->id)->afterCommit();
        });

        return redirect()->route('settings.integrations')->with('status', __('Отправка поставлена в очередь.'));
    }

    /** Only the owner decides who else may manage integrations. */
    public function updatePermission(Request $request, ClubMembership $membership): RedirectResponse
    {
        abort_unless($this->currentClub->membership()?->role === 'owner', 403);
        abort_if($membership->role === 'owner', 404);
        $allowed = (bool) $request->validate(['can_manage_integrations' => ['required', 'boolean']])['can_manage_integrations'];

        DB::transaction(function () use ($membership, $allowed, $request): void {
            $membership->forceFill(['can_manage_integrations' => $allowed])->save();
            $user = $membership->user()->first();
            $this->activity->record(
                'integrations.permission_changed',
                $allowed
                    ? __('Выдан доступ к интеграциям: :user', ['user' => $user?->name])
                    : __('Снят доступ к интеграциям: :user', ['user' => $user?->name]),
                $request->user()->getKey(),
                $user,
                ['can_manage_integrations' => $allowed ? 'true' : 'false'],
            );
        });

        return redirect()->route('settings.integrations')->with('status', __('Права обновлены.'));
    }

    /** @return array{url: string, events: list<string>} */
    private function validateWebhook(Request $request, WebhookUrlGuard $guard): array
    {
        $data = $request->validate([
            'url' => ['required', 'string', 'max:500', 'url:https,http'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['required', 'string', Rule::in(array_keys(config('integrations.webhook_events')))],
        ]);
        $check = $guard->check($data['url']);

        if (is_string($check)) {
            throw ValidationException::withMessages(['url' => match ($check) {
                'https_required' => __('Адрес вебхука должен начинаться с https://.'),
                'private_address' => __('Адрес вебхука указывает на внутреннюю сеть. Нужен публичный адрес.'),
                'port_not_allowed' => __('Разрешены только порты 80, 443, 8080 и 8443.'),
                'host_not_resolved' => __('Не удалось найти хост вебхука.'),
                default => __('Некорректный адрес вебхука.'),
            }]);
        }

        return ['url' => $data['url'], 'events' => array_values(array_unique($data['events']))];
    }

    /** @return list<string>|null */
    private function parseIps(?string $value): ?array
    {
        $entries = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string) $value) ?: []))));

        foreach ($entries as $entry) {
            [$ip, $bits] = array_pad(explode('/', $entry, 2), 2, null);
            $valid = filter_var($ip, FILTER_VALIDATE_IP) !== false
                && ($bits === null || (ctype_digit($bits) && (int) $bits <= (str_contains($ip, ':') ? 128 : 32)));

            if (! $valid || ! IpUtils::checkIp($ip, $entry)) {
                throw ValidationException::withMessages(['allowed_ips' => __('Некорректный IP-адрес или подсеть: :value', ['value' => $entry])]);
            }
        }

        return $entries === [] ? null : $entries;
    }
}
