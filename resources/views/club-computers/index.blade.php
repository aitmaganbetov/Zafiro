@extends('layouts.admin')

@section('page-title', 'Список ПК')

@section('content')
<div class="p-6 lg:p-8">
    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h3 class="text-xl font-semibold text-gray-900 dark:text-white">Список ПК</h3>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Компьютеров клуба: {{ $computers->total() }}</p>
        </div>
    </div>

    <div class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[1900px] text-left text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                    <tr>
                        <th class="px-3 py-3 font-semibold">ID / №</th>
                        <th class="px-3 py-3 font-semibold">ПК / зона</th>
                        <th class="px-3 py-3 font-semibold">Соединение</th>
                        <th class="px-3 py-3 font-semibold">Статус / бронь</th>
                        <th class="px-3 py-3 font-semibold">Позиция</th>
                        <th class="px-3 py-3 font-semibold">Состояние агента</th>
                        <th class="px-3 py-3 font-semibold">MAC</th>
                        <th class="px-3 py-3 font-semibold">IP LAN / WAN</th>
                        <th class="px-3 py-3 font-semibold">CPU</th>
                        <th class="px-3 py-3 font-semibold">RAM</th>
                        <th class="px-3 py-3 font-semibold">GPU</th>
                        <th class="px-3 py-3 font-semibold">ОС</th>
                        <th class="px-3 py-3 font-semibold">Device ID</th>
                        <th class="px-3 py-3 font-semibold">Сессия</th>
                        <th class="px-3 py-3 font-semibold">Агент</th>
                        <th class="px-3 py-3 font-semibold">Последний heartbeat</th>
                        <th class="px-3 py-3 font-semibold">Данные оборудования</th>
                        <th class="px-3 py-3 font-semibold">Создан</th>
                        <th class="px-3 py-3 font-semibold">Обновлён</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse($computers as $computer)
                        <tr class="align-top text-gray-700 dark:text-gray-300">
                            <td class="whitespace-nowrap px-3 py-3 tabular-nums">#{{ $computer->id }} <span class="text-gray-400">/</span> {{ $computer->number }}</td>
                            <td class="px-3 py-3"><div class="font-medium text-gray-900 dark:text-white">{{ $computer->displayName() }}</div><div class="mt-1 text-xs text-gray-500">{{ $computer->zone?->name ?? '—' }} (#{{ $computer->club_zone_id }})</div></td>
                            <td class="whitespace-nowrap px-3 py-3"><span class="inline-flex rounded-full px-2 py-1 text-xs {{ $computer->connectionStatus() === 'online' ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300' : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400' }}">{{ $computer->connectionStatus() === 'online' ? 'Online' : 'Offline' }}</span></td>
                            <td class="whitespace-nowrap px-3 py-3">{{ $computer->status }}{{ $computer->is_reserved ? ' · Забронирован' : '' }}</td>
                            <td class="whitespace-nowrap px-3 py-3 tabular-nums">X {{ $computer->position_x }} · Y {{ $computer->position_y }}</td>
                            <td class="px-3 py-3">{{ $computer->agent_state ?? '—' }}</td>
                            <td class="whitespace-nowrap px-3 py-3 font-mono text-xs">{{ $computer->mac_address ?? '—' }}</td>
                            <td class="px-3 py-3 font-mono text-xs"><div>{{ $computer->local_ip ?? '—' }}</div><div class="mt-1 text-gray-500">{{ $computer->remote_ip ?? '—' }}</div></td>
                            <td class="max-w-56 px-3 py-3"><div class="max-w-56 truncate" title="{{ $computer->cpu_model }}">{{ $computer->cpu_model ?? '—' }}</div><div class="mt-1 text-xs text-gray-500">{{ $computer->cpu_cores ? $computer->cpu_cores.' ядер' : '' }}</div></td>
                            <td class="whitespace-nowrap px-3 py-3">{{ $computer->ram_total_mb ? number_format($computer->ram_total_mb / 1024, 1, ',', ' ').' GB' : '—' }}</td>
                            <td class="max-w-56 px-3 py-3"><div class="max-w-56 truncate" title="{{ $computer->gpu_model }}">{{ $computer->gpu_model ?? '—' }}</div></td>
                            <td class="max-w-48 px-3 py-3"><div class="max-w-48 truncate" title="{{ $computer->os_version }}">{{ $computer->os_version ?? '—' }}</div></td>
                            <td class="max-w-48 px-3 py-3 font-mono text-xs"><div class="max-w-48 truncate" title="{{ $computer->device_id }}">{{ $computer->device_id ?? '—' }}</div></td>
                            <td class="max-w-40 px-3 py-3 font-mono text-xs"><div class="max-w-40 truncate" title="{{ $computer->current_session_id }}">{{ $computer->current_session_id ?? '—' }}</div></td>
                            <td class="whitespace-nowrap px-3 py-3">{{ $computer->agent_version ?? '—' }}</td>
                            <td class="whitespace-nowrap px-3 py-3 text-xs">{{ $computer->last_seen_at?->format('d.m.Y H:i:s') ?? 'Нет данных' }}</td>
                            <td class="whitespace-nowrap px-3 py-3 text-xs">{{ $computer->hardware_reported_at?->format('d.m.Y H:i:s') ?? 'Нет данных' }}</td>
                            <td class="whitespace-nowrap px-3 py-3 text-xs">{{ $computer->created_at?->format('d.m.Y H:i:s') ?? '—' }}</td>
                            <td class="whitespace-nowrap px-3 py-3 text-xs">{{ $computer->updated_at?->format('d.m.Y H:i:s') ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="19" class="px-4 py-12 text-center text-gray-500 dark:text-gray-400">В этом клубе пока нет компьютеров.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($computers->hasPages())
            <div class="border-t border-gray-100 px-4 py-3 dark:border-gray-800">{{ $computers->links() }}</div>
        @endif
    </div>
</div>
@endsection