@extends('layouts.superadmin')
@section('page-title', __('Клубы'))

@section('content')
<p class="mb-4 text-sm text-gray-600 dark:text-gray-400">{{ __('Всего клубов: :total · с активной подпиской: :active, из них на пробном периоде: :trial', ['total' => $totals['clubs'], 'active' => $totals['active'], 'trial' => $totals['trial']]) }}</p>

@if($issues !== [])
    @php($icons = ['offline' => 'fa-plug-circle-xmark', 'webhooks' => 'fa-bolt', 'subscription' => 'fa-hourglass-half'])
    <section class="mb-4 rounded-xl border border-amber-300 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950" aria-labelledby="attention-title">
        <h3 id="attention-title" class="mb-2 font-semibold text-amber-900 dark:text-amber-200"><i class="fas fa-triangle-exclamation mr-1" aria-hidden="true"></i>{{ __('Требуют внимания') }}</h3>
        <ul class="space-y-1 text-sm">
            @foreach($issues as $issue)
                <li class="text-amber-900 dark:text-amber-100"><i class="fas {{ $icons[$issue['kind']] }} mr-1 w-4 text-amber-700" aria-hidden="true"></i><a href="{{ route('superadmin.clubs.show', $issue['club_id']) }}" class="font-medium hover:underline">{{ $issue['club'] }}</a> — {{ $issue['text'] }}</li>
            @endforeach
        </ul>
    </section>
@endif

<form method="GET" class="mb-4 flex flex-wrap items-end gap-3 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
    <label class="min-w-56 flex-1"><span class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Поиск') }}</span><input type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="{{ __('Название или адрес клуба') }}" class="w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2"></label>
    <label><span class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Подписка') }}</span>
        <select name="subscription" class="rounded-lg border border-gray-300 bg-gray-50 px-3 py-2">
            <option value="">{{ __('Все') }}</option>
            <option value="active" @selected(($filters['subscription'] ?? '') === 'active')>{{ __('Активна') }}</option>
            <option value="inactive" @selected(($filters['subscription'] ?? '') === 'inactive')>{{ __('Неактивна') }}</option>
        </select>
    </label>
    @if($sort !== 'name')<input type="hidden" name="sort" value="{{ $sort }}"><input type="hidden" name="direction" value="{{ $direction }}">@endif
    <button type="submit" class="rounded-lg border border-gray-300 px-4 py-2 text-sm">{{ __('Показать') }}</button>
</form>

<div class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
    <table class="w-full text-sm">
        <thead class="border-b border-gray-200 dark:border-gray-700">
            <tr class="text-left text-gray-600">
                <th class="px-4 py-3">@include('superadmin.clubs.sort-link', ['key' => 'name', 'label' => __('Клуб')])</th>
                <th class="px-4 py-3 text-right">@include('superadmin.clubs.sort-link', ['key' => 'online', 'label' => __('В сети / всего')])</th>
                <th class="px-4 py-3 text-right">@include('superadmin.clubs.sort-link', ['key' => 'busy', 'label' => __('Занято')])</th>
                <th class="px-4 py-3 text-right" title="{{ __('Среднее за :minutes минут', ['minutes' => $loadMinutes]) }}">@include('superadmin.clubs.sort-link', ['key' => 'agent_requests', 'label' => __('Агенты, запр./мин')])</th>
                <th class="px-4 py-3 text-right" title="{{ __('Среднее за :minutes минут', ['minutes' => $loadMinutes]) }}">@include('superadmin.clubs.sort-link', ['key' => 'api_requests', 'label' => __('API, запр./мин')])</th>
                <th class="px-4 py-3">@include('superadmin.clubs.sort-link', ['key' => 'last_login', 'label' => __('Вход сотрудника')])</th>
                <th class="px-4 py-3 text-right">@include('superadmin.clubs.sort-link', ['key' => 'sessions', 'label' => __('Сессий за сутки')])</th>
                <th class="px-4 py-3 text-right" title="{{ $latestAgent ? __('Старше версии :version', ['version' => $latestAgent]) : __('Новая версия не объявлена') }}">{{ __('Старый агент') }}</th>
                <th class="px-4 py-3 text-right">@include('superadmin.clubs.sort-link', ['key' => 'webhooks', 'label' => __('Ошибки вебхуков')])</th>
                <th class="px-4 py-3">@include('superadmin.clubs.sort-link', ['key' => 'ends', 'label' => __('Подписка')])</th>
            </tr>
        </thead>
        <tbody>
        @forelse($clubs as $club)
            @php($endsAt = $club->subscription_ends_at ? Carbon\Carbon::parse($club->subscription_ends_at) : null)
            @php($daysLeft = $endsAt?->isFuture() ? (int) ceil(now()->diffInHours($endsAt) / 24) : null)
            <tr class="border-t border-gray-100 tabular-nums dark:border-gray-800">
                <td class="px-4 py-3"><a href="{{ route('superadmin.clubs.show', $club) }}" class="font-medium text-violet-700 hover:underline">{{ $club->name }}</a><div class="text-xs text-gray-500">{{ $club->owner_name ?? '—' }} · <span class="font-mono">{{ $club->slug }}</span></div></td>
                <td class="px-4 py-3 text-right text-gray-700 dark:text-gray-300">{{ (int) $club->online_count }} / {{ (int) $club->computers_count }}</td>
                <td class="px-4 py-3 text-right text-gray-700 dark:text-gray-300">{{ (int) $club->busy_count }}@if($club->computers_count > 0) <span class="text-xs text-gray-500">({{ (int) round($club->busy_count * 100 / $club->computers_count) }}%)</span>@endif</td>
                <td class="px-4 py-3 text-right text-gray-700 dark:text-gray-300">{{ round($club->agent_requests / $loadMinutes, 1) }}</td>
                <td class="px-4 py-3 text-right text-gray-700 dark:text-gray-300">{{ round($club->api_requests / $loadMinutes, 1) }}</td>
                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $club->last_login_at ? Carbon\Carbon::parse($club->last_login_at)->diffForHumans() : '—' }}</td>
                <td class="px-4 py-3 text-right text-gray-700 dark:text-gray-300">{{ (int) $club->sessions_today }}</td>
                <td class="px-4 py-3 text-right {{ ($outdated[$club->id] ?? 0) > 0 ? 'text-amber-700' : 'text-gray-500' }}">{{ $latestAgent ? ($outdated[$club->id] ?? 0) : '—' }}</td>
                <td class="px-4 py-3 text-right {{ $club->webhook_failures > 0 ? 'text-red-600' : 'text-gray-500' }}">{{ (int) $club->webhook_failures }}</td>
                <td class="px-4 py-3">@include('superadmin.clubs.subscription-badge', ['subscription' => $club->subscription])@if($daysLeft !== null)<div class="mt-1 text-xs {{ $daysLeft <= 3 ? 'text-red-600' : 'text-gray-500' }}">{{ trans_choice('ещё :count день|ещё :count дня|ещё :count дней', $daysLeft) }}</div>@endif</td>
            </tr>
        @empty
            <tr><td colspan="10" class="px-4 py-8 text-center text-gray-500">{{ __('Клубы не найдены.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $clubs->links() }}</div>
@endsection
