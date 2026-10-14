@extends('layouts.superadmin')
@section('page-title', __('Обзор платформы'))

@section('content')
@php($card = 'rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-900')
<div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <a href="{{ route('superadmin.clubs.index') }}" class="{{ $card }} hover:border-violet-300">
        <p class="text-sm text-gray-500">{{ __('Клубы') }}</p>
        <p class="mt-1 text-3xl font-bold tabular-nums text-gray-900 dark:text-white">{{ $clubCount }}</p>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('С активной подпиской: :active, из них на пробном периоде: :trial', ['active' => $activeClubs, 'trial' => $trialClubs]) }}</p>
    </a>
    <a href="{{ route('superadmin.users.index') }}" class="{{ $card }} hover:border-violet-300">
        <p class="text-sm text-gray-500">{{ __('Пользователи') }}</p>
        <p class="mt-1 text-3xl font-bold tabular-nums text-gray-900 dark:text-white">{{ (int) $users->total }}</p>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('Заблокировано: :blocked · суперадминов: :superadmins', ['blocked' => (int) $users->blocked, 'superadmins' => (int) $users->superadmins]) }}</p>
    </a>
    <div class="{{ $card }}">
        <p class="text-sm text-gray-500">{{ __('ПК клубов') }}</p>
        <p class="mt-1 text-3xl font-bold tabular-nums text-gray-900 dark:text-white">{{ (int) $computers->total }}</p>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('Сейчас в сети: :online', ['online' => (int) $computers->online]) }}</p>
    </div>
    <div class="{{ $card }}">
        <p class="text-sm text-gray-500">{{ __('Фоновые задачи') }}</p>
        <p class="mt-1 text-3xl font-bold tabular-nums {{ $failedJobs > 0 ? 'text-red-600' : 'text-gray-900 dark:text-white' }}">{{ $failedJobs }}</p>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('Ошибок задач · в очереди: :queued', ['queued' => $queuedJobs]) }}</p>
    </div>
</div>

<div class="mt-6 grid grid-cols-1 gap-4 xl:grid-cols-2">
    <section class="{{ $card }}" aria-labelledby="agent-versions-title">
        <h3 id="agent-versions-title" class="mb-1 font-semibold text-gray-900 dark:text-white">{{ __('Версии агента на ПК') }}</h3>
        <p class="mb-3 text-sm text-gray-600 dark:text-gray-400">{{ $latestAgent ? __('Последняя версия: :version', ['version' => $latestAgent]) : __('Последняя версия не указана (AGENT_LATEST_VERSION).') }}</p>
        @forelse($agentVersions as $version => $count)
            <div class="flex items-center justify-between border-t border-gray-100 py-2 text-sm dark:border-gray-800">
                <span class="font-mono {{ $latestAgent && $version !== $latestAgent ? 'text-amber-700' : 'text-gray-800 dark:text-gray-200' }}">{{ $version }}</span>
                <span class="tabular-nums text-gray-600">{{ trans_choice(':count ПК|:count ПК|:count ПК', $count) }}</span>
            </div>
        @empty
            <p class="text-sm text-gray-500">{{ __('Агенты ещё не сообщали версию.') }}</p>
        @endforelse
    </section>

    <section class="{{ $card }}" aria-labelledby="webhooks-title">
        <h3 id="webhooks-title" class="mb-3 font-semibold text-gray-900 dark:text-white">{{ __('Вебхуки клубов за сутки') }}</h3>
        @php($webhookLabels = ['delivered' => __('Доставлено'), 'pending' => __('В очереди'), 'failed' => __('Ошибка')])
        @forelse($webhooks as $status => $count)
            <div class="flex items-center justify-between border-t border-gray-100 py-2 text-sm dark:border-gray-800">
                <span class="{{ $status === 'failed' ? 'text-red-700' : 'text-gray-800 dark:text-gray-200' }}">{{ $webhookLabels[$status] ?? $status }}</span>
                <span class="tabular-nums text-gray-600">{{ $count }}</span>
            </div>
        @empty
            <p class="text-sm text-gray-500">{{ __('За сутки вебхуков не было.') }}</p>
        @endforelse
    </section>
</div>

<section class="{{ $card }} mt-6" aria-labelledby="recent-title">
    <div class="mb-3 flex items-center justify-between">
        <h3 id="recent-title" class="font-semibold text-gray-900 dark:text-white">{{ __('Последние действия суперадминов') }}</h3>
        <a href="{{ route('superadmin.logs.index') }}" class="text-sm text-violet-700 hover:underline">{{ __('Весь журнал') }}</a>
    </div>
    @include('superadmin.logs.rows', ['logs' => $recentLogs, 'platform' => true])
</section>
@endsection
