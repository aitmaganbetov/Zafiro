@extends('layouts.superadmin')
@section('page-title', __('Сервер'))

@section('content')
@php($card = 'rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-900')
@php($bad = 'text-red-600')
@php($normal = 'text-gray-900 dark:text-white')
@php($silent = $schedulerMinutesAgo === null || $schedulerMinutesAgo > config('monitoring.scheduler_silent_minutes'))
@php($cpu = max(1, (int) ($live['cpu_count'] ?? 1)))
@php($memoryPercent = $live['memory_total_mb'] ? (int) round($live['memory_used_mb'] * 100 / $live['memory_total_mb']) : null)
@php($diskPercent = $live['disk_total_mb'] ? (int) round($live['disk_used_mb'] * 100 / $live['disk_total_mb']) : null)

{{-- Планировщик — главный индикатор: без крона вебхуки, окончание сессий и сбор метрик молча встают. --}}
<section class="mb-4 rounded-xl border p-4 {{ $silent ? 'border-red-300 bg-red-50 dark:border-red-800 dark:bg-red-950' : 'border-green-200 bg-green-50 dark:border-green-900 dark:bg-green-950' }}" aria-labelledby="scheduler-title">
    <h3 id="scheduler-title" class="font-semibold {{ $silent ? 'text-red-800 dark:text-red-200' : 'text-green-800 dark:text-green-200' }}">
        <i class="fas {{ $silent ? 'fa-triangle-exclamation' : 'fa-circle-check' }} mr-1" aria-hidden="true"></i>
        @if($schedulerMinutesAgo === null)
            {{ __('Планировщик ещё не запускался') }}
        @elseif($silent)
            {{ trans_choice('Планировщик молчит :count минуту|Планировщик молчит :count минуты|Планировщик молчит :count минут', $schedulerMinutesAgo) }}
        @else
            {{ __('Планировщик работает') }}
        @endif
    </h3>
    @if($silent)
        <p class="mt-1 text-sm text-red-800 dark:text-red-300">{{ __('Вебхуки, окончание сессий и подписок не обрабатываются. Проверьте строку schedule:run в crontab пользователя сервера.') }}</p>
    @else
        <p class="mt-1 text-sm text-green-800 dark:text-green-300">{{ trans_choice('Последний запуск :count минуту назад|Последний запуск :count минуты назад|Последний запуск :count минут назад', $schedulerMinutesAgo) }}</p>
    @endif
</section>

<div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <div class="{{ $card }}">
        <p class="text-sm text-gray-500">{{ __('Нагрузка CPU (load average)') }}</p>
        <p class="mt-1 text-3xl font-bold tabular-nums {{ ($live['load_5'] ?? 0) > $cpu ? $bad : $normal }}">{{ $live['load_1'] ?? '—' }}</p>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('5 мин: :five · 15 мин: :fifteen · ядер: :cores', ['five' => $live['load_5'] ?? '—', 'fifteen' => $live['load_15'] ?? '—', 'cores' => $live['cpu_count'] ?? '—']) }}</p>
    </div>
    <div class="{{ $card }}">
        <p class="text-sm text-gray-500">{{ __('Память') }}</p>
        <p class="mt-1 text-3xl font-bold tabular-nums {{ $memoryPercent > 90 ? $bad : $normal }}">{{ $memoryPercent !== null ? $memoryPercent.'%' : '—' }}</p>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ $live['memory_total_mb'] ? __(':used из :total ГБ', ['used' => round($live['memory_used_mb'] / 1024, 1), 'total' => round($live['memory_total_mb'] / 1024, 1)]) : __('Нет данных') }}</p>
    </div>
    <div class="{{ $card }}">
        <p class="text-sm text-gray-500">{{ __('Диск') }}</p>
        <p class="mt-1 text-3xl font-bold tabular-nums {{ $diskPercent > config('monitoring.disk_percent') ? $bad : $normal }}">{{ $diskPercent !== null ? $diskPercent.'%' : '—' }}</p>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ $live['disk_total_mb'] ? __(':used из :total ГБ', ['used' => round($live['disk_used_mb'] / 1024), 'total' => round($live['disk_total_mb'] / 1024)]) : __('Нет данных') }}</p>
    </div>
    <div class="{{ $card }}">
        <p class="text-sm text-gray-500">{{ __('Время работы') }}</p>
        <p class="mt-1 text-3xl font-bold tabular-nums {{ $normal }}">{{ $live['uptime_seconds'] !== null ? trans_choice(':count день|:count дня|:count дней', intdiv($live['uptime_seconds'], 86400)) : '—' }}</p>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('ПК клубов в сети: :online из :total', ['online' => (int) $computers->online, 'total' => (int) $computers->total]) }}</p>
    </div>
</div>

<section class="mt-6" aria-labelledby="history-title">
    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
        <h3 id="history-title" class="font-semibold text-gray-900 dark:text-white">{{ __('История') }}</h3>
        <nav class="flex gap-1 text-sm" aria-label="{{ __('Период') }}">
            @foreach(['24h' => __('24 часа'), '7d' => __('7 дней')] as $key => $label)
                <a href="{{ route('superadmin.dashboard', ['period' => $key]) }}" class="rounded-lg px-3 py-1.5 {{ $period === $key ? 'bg-violet-700 text-white' : 'border border-gray-300 text-gray-700 dark:border-gray-700 dark:text-gray-300' }}" @if($period === $key) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>
    </div>
    <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
        @include('superadmin.partials.chart', ['chart' => $charts['load'], 'title' => __('Нагрузка CPU'), 'unit' => ''])
        @include('superadmin.partials.chart', ['chart' => $charts['memory'], 'title' => __('Память'), 'unit' => '%'])
        @include('superadmin.partials.chart', ['chart' => $charts['requests'], 'title' => __('Запросов в минуту'), 'unit' => __('запр./мин')])
        @include('superadmin.partials.chart', ['chart' => $charts['duration'], 'title' => __('Среднее время ответа'), 'unit' => __('мс')])
        @include('superadmin.partials.chart', ['chart' => $charts['errors'], 'title' => __('Доля ошибок 5xx'), 'unit' => '%'])
    </div>
</section>

<div class="mt-6 grid grid-cols-1 gap-4 xl:grid-cols-2">
    <section class="{{ $card }}" aria-labelledby="channels-title">
        <h3 id="channels-title" class="mb-3 font-semibold text-gray-900 dark:text-white">{{ __('Запросы за 15 минут') }}</h3>
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500"><th class="py-2">{{ __('Канал') }}</th><th class="py-2 text-right">{{ __('Запр./мин') }}</th><th class="py-2 text-right">{{ __('Ответ, мс') }}</th><th class="py-2 text-right">{{ __('Ошибки 5xx') }}</th></tr></thead>
            <tbody>
            @foreach($channelLabels as $channel => $label)
                @php($row = $channels[$channel] ?? null)
                <tr class="border-t border-gray-100 tabular-nums dark:border-gray-800">
                    <td class="py-2 text-gray-800 dark:text-gray-200">{{ $label }}</td>
                    <td class="py-2 text-right">{{ $row ? round($row->requests / 15, 1) : 0 }}</td>
                    <td class="py-2 text-right">{{ $row && $row->requests > 0 ? (int) round($row->duration_ms / $row->requests) : '—' }}</td>
                    <td class="py-2 text-right {{ $row && $row->errors > 0 ? 'text-red-600' : '' }}">{{ $row && $row->requests > 0 ? round($row->errors * 100 / $row->requests, 1).'%' : '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </section>

    <section class="{{ $card }}" aria-labelledby="queue-title">
        <h3 id="queue-title" class="mb-3 font-semibold text-gray-900 dark:text-white">{{ __('Очередь и обслуживание') }}</h3>
        <dl class="space-y-2 text-sm">
            <div class="flex justify-between gap-3"><dt class="text-gray-500">{{ __('Ждут выполнения') }}</dt><dd class="tabular-nums {{ $live['queue_pending'] >= config('monitoring.queue_pending') ? 'text-red-600' : '' }}">{{ $live['queue_pending'] }}@if($live['queue_oldest_seconds'] !== null) · {{ __('самое старое — :minutes мин', ['minutes' => intdiv($live['queue_oldest_seconds'], 60)]) }}@endif</dd></div>
            <div class="flex justify-between gap-3"><dt class="text-gray-500">{{ __('Упавшие задания') }}</dt><dd class="tabular-nums {{ $live['queue_failed'] > 0 ? 'text-red-600' : '' }}">{{ $live['queue_failed'] }}</dd></div>
            <div class="flex justify-between gap-3"><dt class="text-gray-500">{{ __('Последний бэкап') }}</dt>
                <dd class="{{ ! $backup['at'] || $backup['at'] < now()->subDays(2)->getTimestamp() ? 'text-red-600' : '' }}">
                    @if(! $backup['configured']){{ __('Не настроен (BACKUP_PATH)') }}@elseif(! $backup['at']){{ __('Файлов нет') }}@else{{ Carbon\Carbon::createFromTimestamp($backup['at'])->diffForHumans() }}@endif
                </dd>
            </div>
            @forelse($certificateHosts as $host)
                @php($expires = $certificates['hosts'][$host] ?? null)
                @php($days = $expires !== null ? (int) floor(($expires - time()) / 86400) : null)
                <div class="flex justify-between gap-3"><dt class="text-gray-500">{{ __('SSL :host', ['host' => $host]) }}</dt>
                    <dd class="tabular-nums {{ $days === null || $days < 14 ? 'text-red-600' : '' }}">{{ $days !== null ? trans_choice('осталось :count день|осталось :count дня|осталось :count дней', $days) : ($certificates === null ? __('Ещё не проверялся') : __('Не удалось проверить')) }}</dd>
                </div>
            @empty
                <div class="flex justify-between gap-3"><dt class="text-gray-500">{{ __('SSL-сертификаты') }}</dt><dd>{{ __('Домены не заданы (MONITORING_SSL_HOSTS)') }}</dd></div>
            @endforelse
        </dl>
    </section>
</div>

<div class="mt-6 grid grid-cols-1 gap-4 xl:grid-cols-2">
    <section class="{{ $card }}" aria-labelledby="database-title">
        <h3 id="database-title" class="mb-1 font-semibold text-gray-900 dark:text-white">{{ __('База данных') }}</h3>
        <p class="mb-3 text-sm text-gray-600 dark:text-gray-400">{{ $live['database_mb'] !== null ? __('Размер: :size МБ', ['size' => $live['database_mb']]) : __('Размер недоступен') }}</p>
        @foreach($tables as $table)
            <div class="flex items-center justify-between border-t border-gray-100 py-2 text-sm dark:border-gray-800">
                <span class="font-mono text-gray-800 dark:text-gray-200">{{ $table->name }}</span>
                <span class="tabular-nums text-gray-600">{{ __(':size МБ · ~:rows строк', ['size' => $table->megabytes, 'rows' => number_format((int) $table->rows, 0, ',', ' ')]) }}</span>
            </div>
        @endforeach
    </section>

    <section class="{{ $card }}" aria-labelledby="errors-title">
        <h3 id="errors-title" class="mb-3 font-semibold text-gray-900 dark:text-white">{{ __('Последние ошибки в логе') }}</h3>
        @forelse($logErrors as $error)
            <div class="border-t border-gray-100 py-2 text-sm dark:border-gray-800">
                <div class="text-xs tabular-nums text-gray-500">{{ $error['at'] }} · <span class="text-red-600">{{ $error['level'] }}</span></div>
                <div class="break-words font-mono text-xs text-gray-800 dark:text-gray-200">{{ $error['message'] }}</div>
            </div>
        @empty
            <p class="text-sm text-gray-500">{{ __('Ошибок в логе нет.') }}</p>
        @endforelse
    </section>
</div>

<section class="{{ $card }} mt-6" aria-labelledby="agent-versions-title">
    <h3 id="agent-versions-title" class="mb-1 font-semibold text-gray-900 dark:text-white">{{ __('Версии агента на ПК') }}</h3>
    <p class="mb-3 text-sm text-gray-600 dark:text-gray-400">
        {{ $latestAgent ? __('Объявлена версия :version: клубы, где агент старше, видят уведомление.', ['version' => $latestAgent]) : __('Новая версия не объявлена: клубы не получают уведомлений.') }}
        @if($agentDownloadUrl)<a href="{{ $agentDownloadUrl }}" target="_blank" rel="noopener noreferrer" class="text-violet-700 hover:underline">{{ __('Ссылка на скачивание') }}</a>@endif
    </p>
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <div>
            @forelse($agentVersions as $version => $count)
                <div class="flex items-center justify-between border-t border-gray-100 py-2 text-sm dark:border-gray-800">
                    <span class="font-mono {{ $latestAgent && $version !== $latestAgent ? 'text-amber-700' : 'text-gray-800 dark:text-gray-200' }}">{{ $version }}</span>
                    <span class="tabular-nums text-gray-600">{{ trans_choice(':count ПК|:count ПК|:count ПК', $count) }}</span>
                </div>
            @empty
                <p class="text-sm text-gray-500">{{ __('Агенты ещё не сообщали версию.') }}</p>
            @endforelse
        </div>
        <div>
            {{-- Объявление новой версии: клубы со старым агентом получают уведомление в панели и на странице «Список ПК». --}}
            @php($input = 'w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100')
            <form method="POST" action="{{ route('superadmin.agent-release.update') }}" class="space-y-3 rounded-lg bg-gray-50 p-4 dark:bg-gray-800">@csrf @method('PUT')
                <h4 class="text-sm font-medium text-gray-900 dark:text-white">{{ __('Объявить новую версию агента') }}</h4>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <label class="text-sm"><span class="mb-1 block text-gray-600">{{ __('Версия') }}</span><input name="latest_version" required pattern="\d{1,4}\.\d{1,4}\.\d{1,6}" placeholder="1.2.0" value="{{ old('latest_version', $agentRelease['latest_version'] ?? '') }}" class="{{ $input }} font-mono"></label>
                    <label class="text-sm"><span class="mb-1 block text-gray-600">{{ __('Дата выпуска') }}</span><input type="date" name="released_at" required value="{{ old('released_at', isset($agentRelease['released_at']) ? substr($agentRelease['released_at'], 0, 10) : now()->toDateString()) }}" class="{{ $input }}"></label>
                </div>
                <label class="block text-sm"><span class="mb-1 block text-gray-600">{{ __('Ссылка на скачивание (https)') }}</span><input type="url" name="download_url" maxlength="500" placeholder="https://github.com/…/releases/tag/agent-v1.2.0" value="{{ old('download_url', $agentRelease['download_url'] ?? '') }}" class="{{ $input }}"></label>
                <div class="flex flex-wrap gap-2">
                    <button type="submit" class="brand-button rounded-lg px-4 py-2 text-sm">{{ __('Объявить версию') }}</button>
                </div>
            </form>
            @if($agentRelease)
                <form method="POST" action="{{ route('superadmin.agent-release.destroy') }}" class="mt-2" onsubmit="return confirm({{ Js::from(__('Снять объявление? Клубы перестанут получать уведомление о новой версии.')) }})">@csrf @method('DELETE')
                    <button type="submit" class="rounded-lg border border-gray-300 px-4 py-2 text-sm">{{ __('Снять объявление') }}</button>
                </form>
            @endif
        </div>
    </div>
</section>

<section class="{{ $card }} mt-6" aria-labelledby="recent-title">
    <div class="mb-3 flex items-center justify-between">
        <h3 id="recent-title" class="font-semibold text-gray-900 dark:text-white">{{ __('Последние действия суперадминов') }}</h3>
        <a href="{{ route('superadmin.logs.index') }}" class="text-sm text-violet-700 hover:underline">{{ __('Весь журнал') }}</a>
    </div>
    @include('superadmin.logs.rows', ['logs' => $recentLogs, 'platform' => true])
</section>
@endsection
