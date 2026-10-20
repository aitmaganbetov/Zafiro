@extends('settings.layout')

{{-- Подразделы страницы: якоря в левом меню настроек. --}}
@php($settingsAnchors = [
    'club-sessions' => __('Сессии и ПК'),
    'agent-settings' => __('Агент на ПК'),
    'shift-limits' => __('Сотрудники на смене'),
    'club-key' => __('Ключ установки клуба'),
    'club-logo' => __('Логотип клуба'),
    'signin-image' => __('Картинка экрана входа'),
    'timezone' => __('Часовой пояс'),
    'agent-pin' => __('PIN-код для выхода из агента'),
])

@section('settings-content')
<h4 class="text-xl font-semibold text-gray-900 dark:text-white">{{ __('Настройки клуба') }}</h4>
<x-settings.card id="club-sessions" aria-labelledby="club-sessions-title">
<h5 id="club-sessions-title" class="mb-2 font-semibold text-gray-900 dark:text-white">{{ __('Сессии и ПК') }}</h5>
<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <div>
        <label for="pc_action_after_session_end" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ __('Действие с ПК после завершения сессии') }}</label>
        <select id="pc_action_after_session_end" name="pc_action_after_session_end" class="brand-focus w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 px-4 py-2 text-gray-900 dark:text-gray-100 focus:outline-none">
            @foreach(['none' => __('Ничего: только заблокировать ПК'), 'restart' => __('Перезагрузить ПК'), 'shutdown' => __('Выключить ПК')] as $action => $actionLabel)
                <option value="{{ $action }}" @selected(old('pc_action_after_session_end', $settings['club']['pc_action_after_session_end'] ?? 'none') === $action)>{{ $actionLabel }}</option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-gray-500">{{ __('Агент выполнит действие, если за это время клиент не оплатит новую сессию.') }}</p>
    </div>
    @foreach([
        'pc_restart_after_session_end' => [__('Через сколько минут выполнить действие после завершения сессии'), 0.5],
        'session_idle_timeout' => [__('Время завершения сессии при бездействии (в минутах)'), 15],
        'session_auto_terminate_when_pc_unavailable' => [__('Время до автоматического завершения сессии при недоступности ПК (в минутах)'), 5],
        'booking_interval' => [__('Интервал между бронированиями (в минутах)'), 30],
    ] as $field => [$label, $default])
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $label }}</label>
            <input type="number" step="0.01" min="0" max="10080" name="{{ $field }}" value="{{ old($field, $settings['club'][$field] ?? $default) }}" required class="brand-focus w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 px-4 py-2 text-gray-900 dark:text-gray-100 focus:outline-none">
        </div>
    @endforeach
</div>
</x-settings.card>

<x-settings.card id="agent-settings" aria-labelledby="agent-settings-title">
    <h5 id="agent-settings-title" class="mb-2 font-semibold text-gray-900 dark:text-white">{{ __('Агент на ПК') }}</h5>
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <div>
            <label for="agent_idle_logout_minutes" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Если нет активной сессии, через сколько отлогинить пользователя (в минутах)') }}</label>
            <input id="agent_idle_logout_minutes" type="number" min="0" max="1440" step="1" name="agent_idle_logout_minutes" value="{{ old('agent_idle_logout_minutes', $settings['club']['agent_idle_logout_minutes'] ?? 5) }}" class="brand-focus w-full rounded-lg border border-gray-300 bg-gray-50 px-4 py-2 text-gray-900 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
            <p class="mt-1 text-xs text-gray-500">{{ __('Клиент вошёл и не купил тариф или сессия закончилась, а он не вышел: агент сам выйдет из аккаунта и освободит ПК. 0 — не выходить.') }}</p>
        </div>
        <div class="flex items-center gap-3 self-start rounded-xl border border-gray-200 p-4 dark:border-gray-700">
            <label for="agent_shop_enabled" class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Включить магазин в агенте') }}</label>
            <input type="hidden" name="agent_shop_enabled" value="0">
            <input id="agent_shop_enabled" type="checkbox" role="switch" name="agent_shop_enabled" value="1" class="settings-switch" @checked(old('agent_shop_enabled', $settings['club']['agent_shop_enabled'] ?? true))>
        </div>
        <div class="rounded-xl border border-gray-200 p-4 md:col-span-2 dark:border-gray-700">
            <div class="flex items-center gap-3">
                <label for="agent_auto_update" class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Автообновление агента') }}</label>
                <input type="hidden" name="agent_auto_update" value="0">
                <input id="agent_auto_update" type="checkbox" role="switch" name="agent_auto_update" value="1" class="settings-switch" @checked(old('agent_auto_update', $settings['club']['agent_auto_update'] ?? true))>
            </div>
            <p class="mt-1 text-xs text-gray-500">{{ __('Новая версия агента ставится сама, когда ПК свободен: никто не вошёл и нет оплаченной сессии. На бездисковой системе обновление пропадает после перезагрузки — выключите автообновление и обновите агент в образе на сервере загрузки.') }}</p>
        </div>
    </div>
</x-settings.card>

<x-settings.card id="shift-limits" aria-labelledby="shift-limits-title">
    <h5 id="shift-limits-title" class="mb-2 font-semibold text-gray-900 dark:text-white">{{ __('Сотрудники на смене') }}</h5>
    <p class="mb-3 text-sm text-gray-600 dark:text-gray-400">{{ __('Столько кассиров и операторов могут одновременно быть в панели. Следующий войдёт, когда кто-то выйдет (или через 2 часа без действий). 0 — без ограничения.') }}</p>
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        @foreach(['max_cashiers_on_shift' => __('Сколько кассиров может быть на смене'), 'max_operators_on_shift' => __('Сколько операторов может быть на смене')] as $field => $label)
            <div>
                <label for="{{ $field }}" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ $label }}</label>
                <input id="{{ $field }}" type="number" min="0" max="100" step="1" name="{{ $field }}" value="{{ old($field, $settings['club'][$field] ?? 0) }}" class="brand-focus w-full rounded-lg border border-gray-300 bg-gray-50 px-4 py-2 text-gray-900 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
            </div>
        @endforeach
    </div>
</x-settings.card>

<x-settings.card id="club-key" aria-labelledby="club-key-title">
    <h5 id="club-key-title" class="mb-2 font-semibold text-gray-900 dark:text-white">{{ __('Ключ установки клуба') }}</h5>
    <p class="mb-3 text-sm text-gray-600 dark:text-gray-400">{{ __('Укажите этот ключ в установленном ПО. По нему система определит клуб.') }}</p>
    @if(in_array($currentMembership->role, ['owner', 'admin'], true))
        <label for="installation-key" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Постоянный ключ клуба') }}</label>
        <div class="flex gap-2"><input id="installation-key" readonly value="{{ $currentClub->installation_key }}" class="min-w-0 flex-1 rounded-lg border border-gray-300 bg-white px-3 py-2 font-mono text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white"><button type="button" data-copy-installation-key class="rounded-lg border border-gray-300 px-3 text-sm dark:border-gray-700">{{ __('Копировать') }}</button></div>
    @else
        <p class="font-mono text-sm text-gray-700 dark:text-gray-300">{{ __('Активен') }} · {{ $currentClub->installation_key_prefix }}••••••••</p>
    @endif
    <p class="mt-2 text-xs text-gray-500">{{ __('Ключ постоянный: изменить или отозвать его нельзя. Он перестанет действовать только после удаления клуба.') }}</p>
</x-settings.card>
@endsection


@section('settings-after')
<x-settings.card id="club-logo" aria-labelledby="club-logo-title">
    <h5 id="club-logo-title" class="mb-2 font-semibold text-gray-900 dark:text-white">{{ __('Логотип клуба') }}</h5>
    <p class="mb-3 text-sm text-gray-600 dark:text-gray-400">{{ __('Логотип и название клуба показываются на свободных мониторах ПК, пока сессия не оплачена. PNG или JPG, до 1 МБ.') }}</p>
    @error('logo')<div class="mb-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">{{ $message }}</div>@enderror
    <div class="flex flex-wrap items-center gap-4">
        <div class="flex h-24 w-40 items-center justify-center rounded-lg bg-black p-3">
            @if($currentClub->logoUrl())
                <img src="{{ $currentClub->logoUrl() }}" alt="{{ __('Логотип клуба') }}" class="max-h-full max-w-full object-contain">
            @else
                <span class="truncate text-sm font-semibold text-white">{{ $currentClub->name }}</span>
            @endif
        </div>
        <div class="min-w-0 flex-1 basis-64">
            @unless($currentClub->logoUrl())<p class="mb-2 text-sm text-gray-600 dark:text-gray-400">{{ __('Логотип не загружен: на мониторах показывается только название клуба.') }}</p>@endunless
            <form method="POST" action="{{ route('settings.logo') }}" enctype="multipart/form-data" class="flex flex-wrap items-center gap-3">
                @csrf
                <input type="file" name="logo" accept="image/png,image/jpeg" required class="min-w-0 text-sm text-gray-700 dark:text-gray-300">
                <button type="submit" class="brand-button rounded-lg px-4 py-2 text-sm">{{ __('Загрузить логотип') }}</button>
            </form>
            @if($currentClub->logoUrl())
                <form method="POST" action="{{ route('settings.logo.destroy') }}" class="mt-2">
                    @csrf @method('DELETE')
                    <button type="submit" class="text-sm text-red-600 hover:underline">{{ __('Удалить логотип') }}</button>
                </form>
            @endif
        </div>
    </div>
</x-settings.card>

<x-settings.card id="signin-image" aria-labelledby="signin-image-title">
    <h5 id="signin-image-title" class="mb-2 font-semibold text-gray-900 dark:text-white">{{ __('Картинка экрана входа') }}</h5>
    <p class="mb-3 text-sm text-gray-600 dark:text-gray-400">{{ __('Показывается слева на экране входа в агенте на ПК клуба вместо стандартной. Лучше горизонтальная, от 1280×720; PNG или JPG, до 3 МБ. На ПК появится в течение минуты.') }}</p>
    @error('image')<div class="mb-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">{{ $message }}</div>@enderror
    <div class="flex flex-wrap items-center gap-4">
        <div class="flex h-28 w-48 items-center justify-center overflow-hidden rounded-lg bg-gray-900">
            @if($currentClub->signInImageUrl())
                <img src="{{ $currentClub->signInImageUrl() }}" alt="{{ __('Картинка экрана входа') }}" class="h-full w-full object-cover">
            @else
                <span class="px-3 text-center text-xs text-gray-400">{{ __('Стандартная картинка Zafiro') }}</span>
            @endif
        </div>
        <div class="min-w-0 flex-1 basis-64">
            <form method="POST" action="{{ route('settings.signin-image') }}" enctype="multipart/form-data" class="flex flex-wrap items-center gap-3">
                @csrf
                <input type="file" name="image" accept="image/png,image/jpeg" required class="min-w-0 text-sm text-gray-700 dark:text-gray-300">
                <button type="submit" class="brand-button rounded-lg px-4 py-2 text-sm">{{ __('Загрузить картинку') }}</button>
            </form>
            @if($currentClub->signInImageUrl())
                <form method="POST" action="{{ route('settings.signin-image.destroy') }}" class="mt-2">
                    @csrf @method('DELETE')
                    <button type="submit" class="text-sm text-red-600 hover:underline">{{ __('Вернуть стандартную') }}</button>
                </form>
            @endif
        </div>
    </div>
</x-settings.card>

<x-settings.card id="timezone" aria-labelledby="timezone-title">
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
</x-settings.card>

<x-settings.card id="agent-pin" aria-labelledby="agent-pin-title">
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

    <h6 class="mb-2 mt-5 font-semibold text-gray-900 dark:text-white">{{ __('Время обслуживания ПК') }}</h6>
    <p class="mb-3 text-sm text-gray-600 dark:text-gray-400">{{ __('Сколько минут сотрудник может работать на ПК после выхода из агента по PIN-коду или входа на ПК. Всё это время ПК отмечен на карте клуба как «Обслуживание», затем агент снова блокирует ПК.') }}</p>
    @if($canManageAgentPin)
        @error('minutes')<div class="mb-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">{{ $message }}</div>@enderror
        <form method="POST" action="{{ route('settings.agent-staff-time') }}" class="flex flex-wrap items-end gap-3">
            @csrf
            <label class="w-40"><span class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Минут') }}</span>
                <input type="number" name="minutes" min="1" max="240" required value="{{ old('minutes', $agentStaffMinutes) }}" class="w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100"></label>
            <button type="submit" class="brand-button rounded-lg px-4 py-2 text-sm">{{ __('Сохранить время') }}</button>
        </form>
    @else
        <p class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ trans_choice(':count минута|:count минуты|:count минут', $agentStaffMinutes) }}</p>
    @endif
</x-settings.card>
@endsection

@push('scripts')
<script>
document.querySelector('[data-copy-installation-key]')?.addEventListener('click', async () => {
    await navigator.clipboard.writeText(document.getElementById('installation-key').value);
});
</script>
@endpush
