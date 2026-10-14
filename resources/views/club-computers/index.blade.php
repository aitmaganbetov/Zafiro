@extends('layouts.admin')

@section('page-title', __('Список ПК'))

@section('content')
<div class="p-6 lg:p-8">
    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h3 class="text-xl font-semibold text-gray-900 dark:text-white">{{ __('Список ПК') }}</h3>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('Компьютеров клуба: :count', ['count' => $computers->total()]) }}</p>
        </div>
    </div>

    @if($agentUpdate)
        <section class="mb-5 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-200" aria-labelledby="agent-update-title">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0 flex-1 basis-80">
                    <h4 id="agent-update-title" class="font-semibold">{{ __('Новая версия агента :version', ['version' => $agentUpdate['version']]) }}</h4>
                    <p class="mt-1">{{ __('Старая версия агента на :count ПК из :total. Агент не обновляется сам: установите новую версию.', ['count' => $agentUpdate['outdated'], 'total' => $agentUpdate['total']]) }}</p>
                </div>
                @if($agentUpdate['download_url'])
                    <a href="{{ $agentUpdate['download_url'] }}" target="_blank" rel="noopener noreferrer" class="brand-button inline-flex items-center gap-2 rounded-lg px-4 py-2"><i class="fas fa-download"></i>{{ __('Скачать установщик') }}</a>
                @endif
            </div>
            <p class="mt-3 font-semibold">{{ __('Бездисковая система — обновить один раз в образе:') }}</p>
            <ol class="mt-1 list-decimal space-y-1 pl-5">
                <li>{{ __('На сервере бездисковой загрузки включите режим обновления образа (супер-рабочая станция) и загрузите с него один ПК.') }}</li>
                <li>{{ __('На этом ПК выйдите из агента по PIN-коду (Ctrl+Alt+P) и запустите установщик поверх старой версии.') }}</li>
                <li>{{ __('Сохраните образ и перезагрузите ПК клуба: после перезагрузки на всех будет новая версия.') }}</li>
            </ol>
            <p class="mt-2 text-xs">{{ __('ПК с обычным диском обновляются установщиком на каждом ПК.') }}</p>
        </section>
    @endif

    <div class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[1900px] text-left text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                    <tr>
                        <th class="px-3 py-3 font-semibold">ID / №</th>
                        <th class="px-3 py-3 font-semibold">{{ __('ПК / зона') }}</th>
                        <th class="px-3 py-3 font-semibold">{{ __('Соединение') }}</th>
                        <th class="px-3 py-3 font-semibold">{{ __('Статус / бронь') }}</th>
                        <th class="px-3 py-3 font-semibold">{{ __('Позиция') }}</th>
                        <th class="px-3 py-3 font-semibold">{{ __('Состояние агента') }}</th>
                        <th class="px-3 py-3 font-semibold">MAC</th>
                        <th class="px-3 py-3 font-semibold">IP LAN / WAN</th>
                        <th class="px-3 py-3 font-semibold">CPU</th>
                        <th class="px-3 py-3 font-semibold">RAM</th>
                        <th class="px-3 py-3 font-semibold">GPU</th>
                        <th class="px-3 py-3 font-semibold">{{ __('ОС') }}</th>
                        <th class="px-3 py-3 font-semibold">Device ID</th>
                        <th class="px-3 py-3 font-semibold">{{ __('Сессия') }}</th>
                        <th class="px-3 py-3 font-semibold">{{ __('Агент') }}</th>
                        <th class="px-3 py-3 font-semibold">{{ __('Последний heartbeat') }}</th>
                        <th class="px-3 py-3 font-semibold">{{ __('Данные оборудования') }}</th>
                        <th class="px-3 py-3 font-semibold">{{ __('Создан') }}</th>
                        <th class="px-3 py-3 font-semibold">{{ __('Обновлён') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse($computers as $computer)
                        <tr class="align-top text-gray-700 dark:text-gray-300">
                            <td class="whitespace-nowrap px-3 py-3 tabular-nums">#{{ $computer->id }} <span class="text-gray-500">/</span> {{ $computer->number }}</td>
                            <td class="px-3 py-3"><div class="font-medium text-gray-900 dark:text-white">{{ $computer->displayName() }}</div><div class="mt-1 text-xs text-gray-500">{{ $computer->zone?->name ?? '—' }} (#{{ $computer->club_zone_id }})</div></td>
                            <td class="whitespace-nowrap px-3 py-3"><span class="inline-flex rounded-full px-2 py-1 text-xs {{ $computer->connectionStatus() === 'online' ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300' : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400' }}">{{ $computer->connectionStatus() === 'online' ? 'Online' : 'Offline' }}</span></td>
                            <td class="whitespace-nowrap px-3 py-3">{{ $computer->status }}{{ $computer->is_reserved ? ' · '.__('Забронирован') : '' }}</td>
                            <td class="whitespace-nowrap px-3 py-3 tabular-nums">X {{ $computer->position_x }} · Y {{ $computer->position_y }}</td>
                            <td class="px-3 py-3">{{ $computer->agent_state ?? '—' }}</td>
                            <td class="whitespace-nowrap px-3 py-3 font-mono text-xs">{{ $computer->mac_address ?? '—' }}</td>
                            <td class="px-3 py-3 font-mono text-xs"><div>{{ $computer->local_ip ?? '—' }}</div><div class="mt-1 text-gray-500">{{ $computer->remote_ip ?? '—' }}</div></td>
                            <td class="max-w-56 px-3 py-3"><div class="max-w-56 truncate" title="{{ $computer->cpu_model }}">{{ $computer->cpu_model ?? '—' }}</div><div class="mt-1 text-xs text-gray-500">{{ $computer->cpu_cores ? trans_choice(':count ядро|:count ядра|:count ядер', $computer->cpu_cores) : '' }}</div></td>
                            <td class="whitespace-nowrap px-3 py-3">{{ $computer->ram_total_mb ? number_format($computer->ram_total_mb / 1024, 1, ',', ' ').' GB' : '—' }}</td>
                            <td class="max-w-56 px-3 py-3"><div class="max-w-56 truncate" title="{{ $computer->gpu_model }}">{{ $computer->gpu_model ?? '—' }}</div></td>
                            <td class="max-w-48 px-3 py-3"><div class="max-w-48 truncate" title="{{ $computer->os_version }}">{{ $computer->os_version ?? '—' }}</div></td>
                            <td class="max-w-48 px-3 py-3 font-mono text-xs"><div class="max-w-48 truncate" title="{{ $computer->device_id }}">{{ $computer->device_id ?? '—' }}</div></td>
                            <td class="max-w-40 px-3 py-3 font-mono text-xs"><div class="max-w-40 truncate" title="{{ $computer->current_session_id }}">{{ $computer->current_session_id ?? '—' }}</div></td>
                            <td class="whitespace-nowrap px-3 py-3 {{ $agentVersions->isOutdated($computer->agent_version) ? 'font-semibold text-amber-700 dark:text-amber-400' : '' }}">{{ $computer->agent_version ?? '—' }}@if($agentVersions->isOutdated($computer->agent_version)) <span class="text-xs font-normal">· {{ __('устарела') }}</span>@endif</td>
                            <td class="whitespace-nowrap px-3 py-3 text-xs">{{ $computer->last_seen_at?->format('d.m.Y H:i:s') ?? __('Нет данных') }}</td>
                            <td class="whitespace-nowrap px-3 py-3 text-xs">{{ $computer->hardware_reported_at?->format('d.m.Y H:i:s') ?? __('Нет данных') }}</td>
                            <td class="whitespace-nowrap px-3 py-3 text-xs">{{ $computer->created_at?->format('d.m.Y H:i:s') ?? '—' }}</td>
                            <td class="whitespace-nowrap px-3 py-3 text-xs">{{ $computer->updated_at?->format('d.m.Y H:i:s') ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="19" class="px-4 py-12 text-center text-gray-500 dark:text-gray-400">{{ __('В этом клубе пока нет компьютеров.') }}</td></tr>
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