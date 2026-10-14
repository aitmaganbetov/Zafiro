{{-- Строки журнала: $logs (PlatformLog или ActivityLog), $platform — журнал суперадминов. --}}
<div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500"><th class="px-3 py-2">{{ __('Время') }}</th><th class="px-3 py-2">{{ __('Клуб') }}</th><th class="px-3 py-2">{{ __('Кто') }}</th><th class="px-3 py-2">{{ __('Событие') }}</th><th class="px-3 py-2">{{ __('Описание') }}</th><th class="px-3 py-2">IP</th></tr></thead>
        <tbody>
        @forelse($logs as $log)
            <tr class="border-t border-gray-100 align-top dark:border-gray-800">
                <td class="whitespace-nowrap px-3 py-2 tabular-nums text-gray-600">{{ $log->created_at->utc()->format('d.m.Y H:i') }} UTC</td>
                <td class="px-3 py-2">@if($log->club)<a href="{{ route('superadmin.clubs.show', $log->club) }}" class="text-violet-700 hover:underline">{{ $log->club->name }}</a>@else<span class="text-gray-400">—</span>@endif</td>
                <td class="px-3 py-2 text-gray-800 dark:text-gray-200">{{ $log->user?->name ?? ($platform ? __('Система') : '—') }}</td>
                <td class="px-3 py-2 text-gray-700 dark:text-gray-300">{{ $platform ? $log->eventLabel() : (isset(\App\Models\ActivityLog::EVENT_LABELS[$log->event]) ? __(\App\Models\ActivityLog::EVENT_LABELS[$log->event]) : $log->event) }}</td>
                <td class="px-3 py-2 text-gray-800 dark:text-gray-200">{{ $log->description }}</td>
                <td class="px-3 py-2 font-mono text-xs text-gray-500">{{ $log->ip_address }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="px-3 py-6 text-center text-gray-500">{{ __('Записей нет.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
