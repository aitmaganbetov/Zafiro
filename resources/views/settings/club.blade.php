@extends('settings.layout')

@section('settings-content')
<h4 class="text-xl font-semibold text-gray-900 dark:text-white mb-4">{{ __('Настройки клуба') }}</h4>
@if(session('status'))<div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">{{ session('status') }}</div>@endif
<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    @foreach([
        'session_idle_timeout' => [__('Время завершения сессии при бездействии (в минутах)'), 15],
        'pc_restart_after_session_end' => [__('Время перезагрузки ПК после завершения сессии (в минутах)'), 0.5],
        'session_auto_terminate_when_pc_unavailable' => [__('Время до автоматического завершения сессии при недоступности ПК (в минутах)'), 5],
        'booking_interval' => [__('Интервал между бронированиями (в минутах)'), 30],
    ] as $field => [$label, $default])
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $label }}</label>
            <input type="number" step="0.01" min="0" max="10080" name="{{ $field }}" value="{{ old($field, $settings['club'][$field] ?? $default) }}" required class="brand-focus w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 px-4 py-2 text-gray-900 dark:text-gray-100 focus:outline-none">
        </div>
    @endforeach
</div>

<section class="mt-6 rounded-lg border border-gray-200 p-4 dark:border-gray-700">
    <h5 class="mb-2 font-semibold text-gray-900 dark:text-white">{{ __('Ключ установки клуба') }}</h5>
    <p class="mb-3 text-sm text-gray-600 dark:text-gray-400">{{ __('Укажите этот ключ в установленном ПО. По нему система определит клуб.') }}</p>
    @if(in_array($currentMembership->role, ['owner', 'admin'], true))
        <label for="installation-key" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Постоянный ключ клуба') }}</label>
        <div class="flex gap-2"><input id="installation-key" readonly value="{{ $currentClub->installation_key }}" class="min-w-0 flex-1 rounded-lg border border-gray-300 bg-white px-3 py-2 font-mono text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white"><button type="button" data-copy-installation-key class="rounded-lg border border-gray-300 px-3 text-sm dark:border-gray-700">{{ __('Копировать') }}</button></div>
    @else
        <p class="font-mono text-sm text-gray-700 dark:text-gray-300">{{ __('Активен') }} · {{ $currentClub->installation_key_prefix }}••••••••</p>
    @endif
    <p class="mt-2 text-xs text-gray-500">{{ __('Ключ постоянный: изменить или отозвать его нельзя. Он перестанет действовать только после удаления клуба.') }}</p>
</section>
@endsection


@section('settings-after')
<section class="mt-6 rounded-lg border border-gray-200 p-4 dark:border-gray-700" aria-labelledby="timezone-title">
    <h5 id="timezone-title" class="mb-2 font-semibold text-gray-900 dark:text-white">{{ __('Часовой пояс') }}</h5>
    <p class="mb-3 text-sm text-gray-600 dark:text-gray-400">{{ __('Все время в системе, отчёты «за сегодня» и часы в верхней панели показываются в этом поясе.') }}</p>
    @error('timezone')<div class="mb-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">{{ $message }}</div>@enderror
    <form method="POST" action="{{ route('settings.timezone') }}" class="flex flex-wrap items-end gap-3">
        @csrf
        <label class="min-w-0 flex-1 basis-64">
            <span class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Пояс') }}</span>
            <select name="timezone" class="w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                @foreach($timezones as $group => $zones)
                    <optgroup label="{{ $group }}">
                        @foreach($zones as $zone => $label)
                            <option value="{{ $zone }}" @selected($zone === $clubTimezone && ($loop->parent->first || ! array_key_exists($zone, reset($timezones))))>{{ $label }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
        </label>
        <button type="submit" class="brand-button rounded-lg px-4 py-2 text-sm">{{ __('Сохранить') }}</button>
    </form>
    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ __('Сейчас в клубе: :time', ['time' => now($clubTimezone)->translatedFormat('d.m.Y H:i')]) }}</p>
</section>

<section class="mt-6 rounded-lg border border-gray-200 p-4 dark:border-gray-700" aria-labelledby="agent-pin-title">
    <h5 id="agent-pin-title" class="mb-2 font-semibold text-gray-900 dark:text-white">{{ __('PIN-код для выхода из агента') }}</h5>
    <p class="mb-3 text-sm text-gray-600 dark:text-gray-400">{{ __('На ПК клуба сотрудник нажимает Ctrl+Alt+P и вводит этот PIN, чтобы закрыть агент. PIN проверяется на сервере, поэтому ПК должен быть в сети.') }}</p>
    <p class="mb-3 text-sm font-medium {{ $agentPinSetAt ? 'text-green-700 dark:text-green-400' : 'text-amber-700 dark:text-amber-400' }}">
        {{ $agentPinSetAt ? __('PIN задан :date', ['date' => $agentPinSetAt->format('d.m.Y H:i')]) : __('PIN не задан: закрыть агент на ПК сейчас нельзя.') }}
    </p>
    @if($canManageAgentPin)
        @error('pin')<div class="mb-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">{{ $message }}</div>@enderror
        <form method="POST" action="{{ route('settings.agent-pin') }}" class="flex flex-wrap items-end gap-3" autocomplete="off">
            @csrf
            <label class="w-40"><span class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Новый PIN') }}</span>
                <input type="password" name="pin" inputmode="numeric" pattern="\d{4,8}" maxlength="8" autocomplete="new-password" class="w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 font-mono text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100"></label>
            <label class="w-40"><span class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Повторите PIN') }}</span>
                <input type="password" name="pin_confirmation" inputmode="numeric" pattern="\d{4,8}" maxlength="8" autocomplete="new-password" class="w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 font-mono text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100"></label>
            <button type="submit" class="brand-button rounded-lg px-4 py-2 text-sm">{{ __('Сохранить PIN') }}</button>
        </form>
        <p class="mt-2 text-xs text-gray-500">{{ __('Оставьте поля пустыми и сохраните, чтобы удалить PIN.') }}</p>
    @endif
</section>
@endsection

@push('scripts')
<script>
document.querySelector('[data-copy-installation-key]')?.addEventListener('click', async () => {
    await navigator.clipboard.writeText(document.getElementById('installation-key').value);
});
</script>
@endpush
