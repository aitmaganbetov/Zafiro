@extends('layouts.admin')

@section('page-title', 'Логи действий')

@section('content')
<div class="p-6 lg:p-8">
    <header class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h3 class="mb-2 text-2xl font-bold text-gray-900 dark:text-white">Логи действий</h3>
            <p class="text-sm text-gray-600 dark:text-gray-400">Операции склада, кассы и входы пользователей.</p>
        </div>
        <span class="text-sm text-gray-500 dark:text-gray-400">Записей: {{ number_format($logs->total(), 0, ',', ' ') }}</span>
    </header>

    <form method="GET" action="{{ route('activity-logs.index') }}" class="mb-6 grid grid-cols-1 items-end gap-3 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900 md:grid-cols-2 xl:grid-cols-5">
        <div>
            <label for="log-from" class="mb-2 block text-xs font-medium text-gray-600 dark:text-gray-400">С даты</label>
            <input id="log-from" name="from" type="date" value="{{ $filters['from'] ?? '' }}" class="w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-white">
        </div>
        <div>
            <label for="log-to" class="mb-2 block text-xs font-medium text-gray-600 dark:text-gray-400">По дату</label>
            <input id="log-to" name="to" type="date" value="{{ $filters['to'] ?? '' }}" class="w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-white">
        </div>
        <div>
            <label for="log-event" class="mb-2 block text-xs font-medium text-gray-600 dark:text-gray-400">Тип события</label>
            <select id="log-event" name="event" class="w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-white">
                <option value="">Все события</option>
                @foreach($eventLabels as $event => $label)<option value="{{ $event }}" @selected(($filters['event'] ?? '') === $event)>{{ $label }}</option>@endforeach
            </select>
        </div>
        <div>
            <label for="log-user" class="mb-2 block text-xs font-medium text-gray-600 dark:text-gray-400">Пользователь</label>
            <select id="log-user" name="user_id" class="w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-white">
                <option value="">Все пользователи</option>
                @foreach($users as $user)<option value="{{ $user->id }}" @selected((string) ($filters['user_id'] ?? '') === (string) $user->id)>{{ $user->name }} · {{ $user->phone }}</option>@endforeach
            </select>
        </div>
        <div class="flex gap-2">
            <input name="search" value="{{ $filters['search'] ?? '' }}" maxlength="120" placeholder="Описание, имя, телефон" aria-label="Поиск по журналу" class="min-w-0 flex-1 rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-white">
            <button type="submit" class="brand-button"><i class="fas fa-filter"></i>Фильтр</button>
        </div>
        <div class="xl:col-span-5">
            <a href="{{ route('activity-logs.index') }}" class="text-xs font-medium text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-white">Сбросить фильтры</a>
        </div>
    </form>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
        <table class="w-full min-w-[850px] text-left text-sm">
            <thead class="border-b border-gray-200 bg-gray-50 text-xs uppercase text-gray-500 dark:border-gray-800 dark:bg-gray-950 dark:text-gray-400">
                <tr><th class="px-4 py-3 font-semibold">Когда</th><th class="px-4 py-3 font-semibold">Пользователь</th><th class="px-4 py-3 font-semibold">Событие</th><th class="px-4 py-3 font-semibold">Описание</th><th class="px-4 py-3 font-semibold">IP</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse($logs as $log)
                    <tr>
                        <td class="whitespace-nowrap px-4 py-3 text-gray-600 dark:text-gray-400">{{ $log->created_at->format('d.m.Y H:i:s') }}</td>
                        <td class="px-4 py-3 text-gray-800 dark:text-gray-200">{{ $log->user?->name ?? 'Система' }}</td>
                        <td class="px-4 py-3"><span class="inline-flex rounded-full bg-gray-100 px-2 py-1 text-xs font-medium text-gray-700 dark:bg-gray-800 dark:text-gray-300">{{ $eventLabels[$log->event] ?? $log->event }}</span></td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $log->description }}
                            @if($log->details)<div class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-gray-500 dark:text-gray-400">@foreach($log->details as $key => $value)<span>{{ $key }}: {{ is_array($value) ? implode(', ', $value) : $value }}</span>@endforeach</div>@endif
                        </td>
                        <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400">{{ $log->ip_address ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-12 text-center text-sm text-gray-500 dark:text-gray-400">По выбранным фильтрам записей нет.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $logs->links() }}</div>
</div>
@endsection