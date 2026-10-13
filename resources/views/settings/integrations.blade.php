@extends('settings.layout')

@php
    $field = 'w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100';
    $card = 'rounded-xl border border-gray-200 p-4 dark:border-gray-700';
    $badge = fn (string $tone) => [
        'green' => 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300',
        'red' => 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300',
        'amber' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300',
        'gray' => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
    ][$tone].' inline-block rounded-full px-2 py-0.5 text-xs font-medium';
@endphp

@section('settings-after')
<div class="space-y-8">
    <section>
        <h4 class="mb-2 text-xl font-semibold text-gray-900 dark:text-white">{{ __('API и интеграции') }}</h4>
        <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('Ключи API дают внешним системам доступ только к данным этого клуба и только к выбранным операциям.') }}</p>
        <div class="mt-3 flex flex-wrap gap-2 text-sm">
            <span class="break-all rounded-lg bg-gray-100 px-3 py-1 font-mono text-gray-700 dark:bg-gray-800 dark:text-gray-300">{{ url('/api/v1') }}</span>
            <a href="{{ route('api-docs') }}" target="_blank" rel="noopener" class="rounded-lg border border-gray-300 px-3 py-1 text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"><i class="fas fa-book mr-1"></i>{{ __('Документация API') }}</a>
        </div>
        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('Не встраивайте ключи в мобильные приложения и сайты: мобильные приложения входят через аккаунт пользователя.') }}</p>
    </section>

    @if ($errors->any())
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300" role="alert">
            @foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach
        </div>
    @endif

    @if (! empty($created['secret']))
        <div class="rounded-xl border-2 border-amber-300 bg-amber-50 p-4 dark:border-amber-700 dark:bg-amber-900/20" role="status">
            <p class="mb-2 font-semibold text-amber-900 dark:text-amber-200">
                {{ $created['type'] === 'key' ? __('Новый API-ключ «:name»', ['name' => $created['name']]) : __('Секрет подписи вебхука :name', ['name' => $created['name']]) }}
            </p>
            <div class="flex gap-2">
                <input id="new-secret" readonly value="{{ $created['secret'] }}" class="min-w-0 flex-1 rounded-lg border border-amber-300 bg-white px-3 py-2 font-mono text-sm dark:border-amber-700 dark:bg-gray-900 dark:text-white" autocomplete="off" spellcheck="false">
                <button type="button" data-copy-target="new-secret" class="rounded-lg border border-amber-400 px-3 text-sm text-amber-900 dark:text-amber-200">{{ __('Копировать') }}</button>
            </div>
            <p class="mt-2 text-sm text-amber-900 dark:text-amber-200">{{ __('Сохраните его сейчас: он показывается один раз, в Zafiro хранится только его хеш.') }}</p>
        </div>
    @endif

    {{-- API keys --}}
    <section>
        <h5 class="mb-3 text-lg font-semibold text-gray-900 dark:text-white">{{ __('Ключи API') }}</h5>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[680px] text-sm">
                <thead><tr class="text-left text-gray-600 dark:text-gray-400">
                    <th class="px-3 py-2">{{ __('Название') }}</th><th class="px-3 py-2">{{ __('Ключ') }}</th><th class="px-3 py-2">{{ __('Права') }}</th><th class="px-3 py-2">{{ __('Ограничения') }}</th><th class="px-3 py-2">{{ __('Последнее использование') }}</th><th class="px-3 py-2">{{ __('Статус') }}</th><th class="px-3 py-2"></th>
                </tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse ($keys as $key)
                    <tr class="align-top">
                        <td class="px-3 py-2"><span class="font-medium text-gray-900 dark:text-white">{{ $key->name }}</span><br><span class="text-xs text-gray-500">{{ $key->creator?->name }}, {{ $key->created_at?->format('d.m.Y') }}</span></td>
                        <td class="px-3 py-2 font-mono text-xs text-gray-700 dark:text-gray-300">{{ $key->prefix }}_…</td>
                        <td class="px-3 py-2">
                            @foreach ($key->scopes as $scope)
                                <span class="{{ $badge(($scopes[$scope]['dangerous'] ?? false) ? 'amber' : 'gray') }} mb-1" title="{{ __($scopes[$scope]['label'] ?? $scope) }}">{{ $scope }}</span>
                            @endforeach
                        </td>
                        <td class="px-3 py-2 text-xs text-gray-600 dark:text-gray-400">
                            <div>{{ __('IP') }}: {{ $key->allowed_ips ? implode(', ', $key->allowed_ips) : __('любой') }}</div>
                            <div>{{ __('Лимит') }}: {{ min($key->rate_limit_per_minute ?? config('integrations.default_rate_limit_per_minute'), config('integrations.max_rate_limit_per_minute')) }}/{{ __('мин') }}</div>
                            <div>{{ __('Действует до') }}: {{ $key->expires_at?->format('d.m.Y') ?? __('бессрочно') }}</div>
                        </td>
                        <td class="px-3 py-2 text-xs text-gray-600 dark:text-gray-400">{{ $key->last_used_at ? $key->last_used_at->format('d.m.Y H:i').' · '.$key->last_used_ip : '—' }}</td>
                        <td class="px-3 py-2">
                            @if ($key->revoked_at)
                                <span class="{{ $badge('red') }}">{{ __('отозван') }}</span>
                            @elseif ($key->expires_at?->isPast())
                                <span class="{{ $badge('gray') }}">{{ __('истёк') }}</span>
                            @else
                                <span class="{{ $badge('green') }}">{{ __('активен') }}</span>
                            @endif
                        </td>
                        <td class="px-3 py-2">
                            @if (! $key->revoked_at)
                                <div class="flex flex-col items-stretch gap-2">
                                <form method="POST" action="{{ route('settings.integrations.keys.rotate', $key) }}" onsubmit="return confirm(@js(__('Выпустить новый ключ с теми же правами? Старый перестанет работать сразу.')))">
                                    @csrf
                                    <button type="submit" class="w-full rounded-lg border border-gray-300 px-3 py-1 text-sm dark:border-gray-700 dark:text-gray-200">{{ __('Перевыпустить') }}</button>
                                </form>
                                <form method="POST" action="{{ route('settings.integrations.keys.revoke', $key) }}" onsubmit="return confirm(@js(__('Отозвать ключ? Интеграции с ним перестанут работать.')))">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="w-full rounded-lg border border-red-200 px-3 py-1 text-sm text-red-700 dark:border-red-800 dark:text-red-300">{{ __('Отозвать') }}</button>
                                </form>
                                </div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-3 py-4 text-gray-500">{{ __('Ключей пока нет.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <form method="POST" action="{{ route('settings.integrations.keys.store') }}" class="{{ $card }} mt-4 space-y-4">
            @csrf
            <h6 class="font-semibold text-gray-900 dark:text-white">{{ __('Новый ключ') }}</h6>
            <div class="grid gap-4 md:grid-cols-2">
                <label><span class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Название') }}</span><input name="name" value="{{ old('name') }}" required maxlength="100" placeholder="{{ __('Например: 1С склад') }}" class="{{ $field }}"></label>
                <label><span class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Действует до') }}</span><input type="date" name="expires_at" value="{{ old('expires_at') }}" min="{{ now()->addDay()->toDateString() }}" class="{{ $field }}"><span class="mt-1 block text-xs text-gray-500">{{ __('Пусто — бессрочно.') }}</span></label>
                <label><span class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Разрешённые IP или подсети') }}</span><textarea name="allowed_ips" rows="2" maxlength="2000" placeholder="203.0.113.10&#10;198.51.100.0/24" class="{{ $field }} font-mono text-sm">{{ old('allowed_ips') }}</textarea><span class="mt-1 block text-xs text-gray-500">{{ __('По одному в строке. Пусто — с любого адреса.') }}</span></label>
                <label><span class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Запросов в минуту') }}</span><input type="number" name="rate_limit_per_minute" value="{{ old('rate_limit_per_minute') }}" min="1" max="{{ config('integrations.max_rate_limit_per_minute') }}" placeholder="{{ config('integrations.default_rate_limit_per_minute') }}" class="{{ $field }}"></label>
            </div>
            <fieldset>
                <legend class="mb-2 text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Права ключа') }}</legend>
                <div class="grid gap-2 md:grid-cols-2">
                    @foreach ($scopes as $scope => $meta)
                        <label class="flex items-start gap-2 rounded-lg border px-3 py-2 text-sm {{ $meta['dangerous'] ? 'border-amber-300 bg-amber-50 dark:border-amber-700 dark:bg-amber-900/20' : 'border-gray-200 dark:border-gray-700' }}">
                            <input type="checkbox" name="scopes[]" value="{{ $scope }}" @checked(in_array($scope, old('scopes', []), true)) class="mt-1">
                            <span><span class="block text-gray-900 dark:text-gray-100">{{ __($meta['label']) }}</span><span class="font-mono text-xs text-gray-500">{{ $scope }}</span>@if ($meta['dangerous'])<span class="ml-2 text-xs font-medium text-amber-800 dark:text-amber-300">{{ __('опасное') }}</span>@endif</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
            <button type="submit" class="brand-button rounded-lg px-4 py-2 text-sm">{{ __('Создать ключ') }}</button>
        </form>
    </section>

    {{-- Webhooks --}}
    <section>
        <h5 class="mb-1 text-lg font-semibold text-gray-900 dark:text-white">{{ __('Вебхуки') }}</h5>
        <p class="mb-3 text-sm text-gray-600 dark:text-gray-400">{{ __('Zafiro отправляет POST-запрос с подписью HMAC-SHA256 в заголовке X-Zafiro-Signature. Неудачные отправки повторяются.') }}</p>
        <div class="space-y-3">
            @forelse ($webhooks as $webhook)
                <div class="{{ $card }}">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="break-all font-mono text-sm text-gray-900 dark:text-white">{{ $webhook->url }}</p>
                            <p class="mt-1 text-xs text-gray-500">{{ implode(', ', $webhook->events) }}</p>
                            <p class="mt-1 text-xs text-gray-500">
                                {{ __('Успешно') }}: {{ $webhook->last_success_at?->format('d.m.Y H:i') ?? '—' }} ·
                                {{ __('Ошибка') }}: {{ $webhook->last_failure_at?->format('d.m.Y H:i') ?? '—' }} ·
                                {{ __('Ошибок подряд') }}: {{ $webhook->failure_count }}
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <span class="{{ $badge($webhook->is_active ? 'green' : 'gray') }}">{{ $webhook->is_active ? __('включён') : __('выключен') }}</span>
                            <form method="POST" action="{{ route('settings.integrations.webhooks.update', $webhook) }}">
                                @csrf @method('PATCH')
                                <input type="hidden" name="is_active" value="{{ $webhook->is_active ? 0 : 1 }}">
                                <button type="submit" class="rounded-lg border border-gray-300 px-3 py-1 text-sm dark:border-gray-700 dark:text-gray-200">{{ $webhook->is_active ? __('Выключить') : __('Включить') }}</button>
                            </form>
                            <form method="POST" action="{{ route('settings.integrations.webhooks.secret', $webhook) }}" onsubmit="return confirm(@js(__('Выпустить новый секрет? Получатель должен будет обновить его.')))">
                                @csrf
                                <button type="submit" class="rounded-lg border border-gray-300 px-3 py-1 text-sm dark:border-gray-700 dark:text-gray-200">{{ __('Новый секрет') }}</button>
                            </form>
                            <form method="POST" action="{{ route('settings.integrations.webhooks.destroy', $webhook) }}" onsubmit="return confirm(@js(__('Удалить вебхук?')))">
                                @csrf @method('DELETE')
                                <button type="submit" class="rounded-lg border border-red-200 px-3 py-1 text-sm text-red-700 dark:border-red-800 dark:text-red-300">{{ __('Удалить') }}</button>
                            </form>
                        </div>
                    </div>
                </div>
            @empty
                <p class="text-sm text-gray-500">{{ __('Вебхуков пока нет.') }}</p>
            @endforelse
        </div>

        <form method="POST" action="{{ route('settings.integrations.webhooks.store') }}" class="{{ $card }} mt-4 space-y-4">
            @csrf
            <h6 class="font-semibold text-gray-900 dark:text-white">{{ __('Новый вебхук') }}</h6>
            <label class="block"><span class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Адрес (https://)') }}</span><input type="url" name="url" value="{{ old('url') }}" required maxlength="500" placeholder="https://example.com/zafiro/webhook" class="{{ $field }} font-mono text-sm"></label>
            <fieldset>
                <legend class="mb-2 text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('События') }}</legend>
                <div class="grid gap-2 md:grid-cols-2">
                    @foreach ($events as $event => $label)
                        <label class="flex items-start gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm dark:border-gray-700">
                            <input type="checkbox" name="events[]" value="{{ $event }}" @checked(in_array($event, old('events', []), true)) class="mt-1">
                            <span><span class="block text-gray-900 dark:text-gray-100">{{ __($label) }}</span><span class="font-mono text-xs text-gray-500">{{ $event }}</span></span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
            <button type="submit" class="brand-button rounded-lg px-4 py-2 text-sm">{{ __('Добавить вебхук') }}</button>
        </form>

        @if ($deliveries->isNotEmpty())
            <h6 class="mb-2 mt-6 font-semibold text-gray-900 dark:text-white">{{ __('Последние отправки') }}</h6>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[640px] text-sm">
                    <thead><tr class="text-left text-gray-600 dark:text-gray-400"><th class="px-3 py-2">{{ __('Время') }}</th><th class="px-3 py-2">{{ __('Событие') }}</th><th class="px-3 py-2">{{ __('Адрес') }}</th><th class="px-3 py-2">{{ __('Статус') }}</th><th class="px-3 py-2">{{ __('Попыток') }}</th><th class="px-3 py-2"></th></tr></thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($deliveries as $delivery)
                        <tr>
                            <td class="px-3 py-2 whitespace-nowrap text-gray-600 dark:text-gray-400">{{ $delivery->created_at?->format('d.m H:i:s') }}</td>
                            <td class="px-3 py-2 font-mono text-xs">{{ $delivery->event }}</td>
                            <td class="px-3 py-2 max-w-[16rem] truncate font-mono text-xs text-gray-600 dark:text-gray-400" title="{{ $delivery->endpoint?->url }}">{{ $delivery->endpoint?->url }}</td>
                            <td class="px-3 py-2">
                                <span class="{{ $badge(['delivered' => 'green', 'failed' => 'red', 'pending' => 'amber'][$delivery->status] ?? 'gray') }}">{{ ['delivered' => __('доставлено'), 'failed' => __('ошибка'), 'pending' => __('в очереди'), 'skipped' => __('пропущено')][$delivery->status] ?? $delivery->status }}</span>
                                @if ($delivery->last_error)<span class="block text-xs text-gray-500">{{ $delivery->last_error }}</span>@endif
                            </td>
                            <td class="px-3 py-2 tabular-nums">{{ $delivery->attempts }}</td>
                            <td class="px-3 py-2 text-right">
                                @if (in_array($delivery->status, ['failed', 'skipped'], true))
                                    <form method="POST" action="{{ route('settings.integrations.deliveries.retry', $delivery) }}">@csrf<button type="submit" class="rounded-lg border border-gray-300 px-3 py-1 text-sm dark:border-gray-700 dark:text-gray-200">{{ __('Повторить') }}</button></form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- Who may manage integrations --}}
    @if ($isOwner)
        <section>
            <h5 class="mb-1 text-lg font-semibold text-gray-900 dark:text-white">{{ __('Доступ к интеграциям') }}</h5>
            <p class="mb-3 text-sm text-gray-600 dark:text-gray-400">{{ __('Владелец управляет интеграциями всегда. Ключи сотрудника перестают работать, когда у него снимают доступ.') }}</p>
            <div class="divide-y divide-gray-100 rounded-xl border border-gray-200 dark:divide-gray-800 dark:border-gray-700">
                @forelse ($staff as $member)
                    <div class="flex items-center justify-between gap-3 px-4 py-2 text-sm">
                        <span class="text-gray-900 dark:text-gray-100">{{ $member->user?->name }} <span class="text-xs text-gray-500">· {{ ['admin' => __('Администратор'), 'manager' => __('Менеджер'), 'cashier' => __('Кассир'), 'operator' => __('Оператор')][$member->role] ?? $member->role }}</span></span>
                        <form method="POST" action="{{ route('settings.integrations.permissions.update', $member) }}">
                            @csrf @method('PATCH')
                            <input type="hidden" name="can_manage_integrations" value="{{ $member->can_manage_integrations ? 0 : 1 }}">
                            <button type="submit" class="rounded-lg border px-3 py-1 text-sm {{ $member->can_manage_integrations ? 'border-green-300 text-green-800 dark:border-green-700 dark:text-green-300' : 'border-gray-300 text-gray-700 dark:border-gray-700 dark:text-gray-300' }}" aria-label="{{ $member->can_manage_integrations ? __('Снять доступ к интеграциям: :user', ['user' => $member->user?->name]) : __('Выдать доступ к интеграциям: :user', ['user' => $member->user?->name]) }}">{{ $member->can_manage_integrations ? __('Есть доступ') : __('Нет доступа') }}</button>
                        </form>
                    </div>
                @empty
                    <p class="px-4 py-3 text-sm text-gray-500">{{ __('В клубе нет сотрудников.') }}</p>
                @endforelse
            </div>
        </section>
    @endif

    {{-- Recent API calls --}}
    <section>
        <h5 class="mb-3 text-lg font-semibold text-gray-900 dark:text-white">{{ __('Последние запросы к API') }}</h5>
        @if ($requests->isEmpty())
            <p class="text-sm text-gray-500">{{ __('Запросов пока не было.') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[640px] text-sm">
                    <thead><tr class="text-left text-gray-600 dark:text-gray-400"><th class="px-3 py-2">{{ __('Время') }}</th><th class="px-3 py-2">{{ __('Ключ') }}</th><th class="px-3 py-2">{{ __('Запрос') }}</th><th class="px-3 py-2">{{ __('Статус') }}</th><th class="px-3 py-2 text-right">{{ __('мс') }}</th><th class="px-3 py-2">IP</th></tr></thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($requests as $entry)
                        <tr>
                            <td class="px-3 py-2 whitespace-nowrap text-gray-600 dark:text-gray-400">{{ $entry->created_at?->format('d.m H:i:s') }}</td>
                            <td class="px-3 py-2 font-mono text-xs">{{ $entry->key_prefix ?? '—' }}</td>
                            <td class="px-3 py-2 font-mono text-xs break-all">{{ $entry->method }} {{ $entry->endpoint }}</td>
                            <td class="px-3 py-2"><span class="{{ $badge($entry->status < 300 ? 'green' : ($entry->status < 500 ? 'amber' : 'red')) }}">{{ $entry->status }}</span></td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $entry->duration_ms }}</td>
                            <td class="px-3 py-2 font-mono text-xs">{{ $entry->ip }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
@endsection

@push('scripts')
<script>
document.querySelectorAll('[data-copy-target]').forEach((button) => {
    button.addEventListener('click', async () => {
        const input = document.getElementById(button.dataset.copyTarget);
        input.select();
        try { await navigator.clipboard.writeText(input.value); } catch { document.execCommand('copy'); }
        button.textContent = @js(__('Скопировано'));
    });
});
</script>
@endpush
