@extends('layouts.admin')

@section('page-title', __('Зоны и тарифы'))


@section('content')
<div class="p-6 lg:p-8">
    <header class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h3 class="mb-2 text-2xl font-bold text-gray-900 dark:text-white">{{ __('Зоны и тарифы') }}</h3>
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('Настройте стоимость и длительность игровых сеансов.') }}</p>
        </div>
        <button id="open-tariff-dialog" type="button" class="tariff-add-button"><i class="fas fa-plus"></i>{{ __('Добавить тариф') }}</button>
    </header>

    @if (session('status'))
        <div class="mb-5 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">
            {{ session('status') }}
        </div>
    @endif

    @php
        // Form values: the old input, else the tariff being edited, else the default.
        $value = fn (string $key, mixed $default = null): mixed => old($key, $editing !== null ? ($editingValues[$key] ?? $default) : $default);
        $weekdays = ['mon' => __('Пн'), 'tue' => __('Вт'), 'wed' => __('Ср'), 'thu' => __('Чт'), 'fri' => __('Пт'), 'sat' => __('Сб'), 'sun' => __('Вс')];
        $periods = $value('validity_periods', [['start_day' => 'mon', 'start_time' => '00:00', 'end_day' => 'sun', 'end_time' => '23:59']]);
        $tariffColors = ['#ff0169', '#e11d48', '#c026d3', '#9333ea', '#6366f1', '#2563eb', '#0ea5e9', '#06b6d4', '#14b8a6', '#22c55e', '#84cc16', '#a3e635', '#eab308', '#facc15', '#f97316', '#fb7185', '#fda4af', '#fbcfe8', '#e9d5ff', '#c4b5fd', '#bfdbfe', '#bae6fd', '#a5f3fc', '#99f6e4', '#bbf7d0', '#d9f99d', '#fef08a', '#fef3c7', '#fed7aa', '#fdba74'];
    @endphp
    {{-- Editing opens the same form filled in; closing it goes back to the list. --}}
    <dialog id="tariff-dialog" class="tariff-dialog" @if($errors->any() || $editing !== null) data-open-on-load @endif @if($editing !== null) data-close-href="{{ route('tariffs.index') }}" @endif>
        <div class="tariff-dialog-shell">
            <header class="tariff-dialog-head">
                <h4 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $editing !== null ? __('Изменить тариф') : __('Новый тариф') }}</h4>
                <button type="button" data-close-tariff-dialog class="grid h-9 w-9 place-items-center rounded-lg text-gray-500 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800" aria-label="{{ __('Закрыть') }}"><i class="fas fa-times"></i></button>
            </header>
            <div class="tariff-dialog-body">
                @if ($errors->any())
                    <div class="mb-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300">{{ $errors->first() }}</div>
                @endif
        <form action="{{ $editing !== null ? route('tariffs.update', $editing) : route('tariffs.store') }}" method="POST" class="space-y-7">
            @csrf
            @if($editing !== null) @method('PATCH') @endif
            <input type="hidden" name="is_active" value="0">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label for="payment-method" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Как клиент может оплатить этот абонемент?') }}</label>
                    <select id="payment-method" name="payment_method" required class="w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-white">
                        <option value="main_balance_only" @selected($value('payment_method', 'main_balance_only') === 'main_balance_only')>{{ __('Можно приобрести только за основной баланс') }}</option>
                        <option value="main_or_bonus" @selected($value('payment_method') === 'main_or_bonus')>{{ __('Можно приобрести за основной или бонусный баланс') }}</option>
                    </select>
                </div>
                <div>
                    <label for="tariff-name" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Название') }}</label>
                    <input id="tariff-name" name="name" value="{{ $value('name') }}" required maxlength="100" class="w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-white">
                </div>
                <div>
                    <label for="tariff-duration" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Длительность') }}</label>
                    <div class="tariff-duration-fields">
                        <input id="tariff-duration" name="duration_value" type="number" value="{{ $value('duration_value', 1) }}" required min="1" max="10080" class="w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-white">
                        <select name="duration_unit" class="rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-white"><option value="hours" @selected($value('duration_unit', 'hours') === 'hours')>{{ __('Часов') }}</option><option value="minutes" @selected($value('duration_unit') === 'minutes')>{{ __('Минут') }}</option><option value="days" @selected($value('duration_unit') === 'days')>{{ __('Дней') }}</option></select>
                    </div>
                </div>
                <div>
                    <label for="tariff-price" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Стоимость') }}</label>
                    <input id="tariff-price" name="price" type="number" value="{{ $value('price') }}" required min="0" max="9999999999.99" step="0.01" class="w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-white">
                </div>
                <div class="flex items-center gap-3 pt-6">
                    <input type="hidden" name="discount_enabled" value="0"><input id="discount-enabled" type="checkbox" name="discount_enabled" value="1" @checked($value('discount_enabled', '0') === '1') class="brand-check rounded border-gray-300">
                    <label for="discount-enabled" class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Применять скидку') }}</label>
                </div>
                <div>
                    <span class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Цвет') }}</span>
                    <div class="flex flex-wrap gap-2">
                        @foreach($tariffColors as $color)
                            <label class="cursor-pointer"><input class="tariff-color-radio peer sr-only" type="radio" name="color" value="{{ $color }}" @checked($value('color', '#ff0169') === $color)><span class="tariff-color-swatch tariff-color-{{ substr($color, 1) }}"></span></label>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 border-t border-gray-200 pt-6 dark:border-gray-800 md:grid-cols-2">
                <div>
                    <label for="club-name" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Клуб') }}</label>
                    <input id="club-name" name="club_name" value="{{ $value('club_name', config('app.name')) }}" required maxlength="100" class="w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-white">
                </div>
                <div>
                    <label for="tariff-zones" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Зоны') }}</label>
                    <select id="tariff-zones" name="zone_ids[]" multiple size="3" class="w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-white">
                        @foreach($zones as $zone)<option value="{{ $zone->id }}" @selected(in_array((string) $zone->id, array_map('strval', $value('zone_ids', [])), true))>{{ $zone->name }}</option>@endforeach
                    </select>
                    @if($zones->isEmpty())<p class="mt-1 text-xs text-gray-500">{{ __('Сначала создайте зону.') }}</p>@endif
                </div>
                <div class="space-y-4">
                    <div>
                        <input type="hidden" name="usage_limit_enabled" value="0">
                        <label class="flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-300"><input type="checkbox" name="usage_limit_enabled" value="1" @checked($value('usage_limit_enabled', '0') === '1') class="brand-check rounded border-gray-300">{{ __('Ограничить использование') }}</label>
                        <p class="mb-2 text-xs text-gray-500">{{ __('Абонемент сгорит через указанное время после активации.') }}</p>
                        <div class="tariff-duration-fields"><input name="usage_limit_value" type="number" min="1" max="10080" value="{{ $value('usage_limit_value') }}" class="w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800"><select name="usage_limit_unit" class="rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800"><option value="hours" @selected($value('usage_limit_unit', 'hours') === 'hours')>{{ __('Часов') }}</option><option value="minutes" @selected($value('usage_limit_unit') === 'minutes')>{{ __('Минут') }}</option><option value="days" @selected($value('usage_limit_unit') === 'days')>{{ __('Дней') }}</option></select></div>
                    </div>
                    <div>
                        <input type="hidden" name="quantity_limit_enabled" value="0">
                        <label class="flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-300"><input type="checkbox" name="quantity_limit_enabled" value="1" @checked($value('quantity_limit_enabled', '0') === '1') class="brand-check rounded border-gray-300">{{ __('Ограничить количество') }}</label>
                        <p class="mb-2 text-xs text-gray-500">{{ __('Сколько абонементов будет доступно к продаже.') }}</p>
                        <input name="quantity_limit" type="number" min="1" max="1000000" value="{{ $value('quantity_limit') }}" class="w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800">
                    </div>
                </div>
            </div>

            {{-- Sale time: before the start and after the end the tariff is not shown or sold. --}}
            <section class="border-t border-gray-200 pt-6 dark:border-gray-800">
                <h5 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Время продажи') }}</h5>
                <p class="mb-3 text-xs text-gray-500">{{ __('До начала и после окончания тариф не показывается в агенте и на кассе. Пусто — без ограничения.') }}</p>
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <label class="text-xs text-gray-500">{{ __('Продажа с') }}<input name="sale_starts_at" type="datetime-local" value="{{ $value('sale_starts_at') }}" class="mt-1 w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-white"></label>
                    <label class="text-xs text-gray-500">{{ __('Продажа до') }}<input name="sale_ends_at" type="datetime-local" value="{{ $value('sale_ends_at') }}" class="mt-1 w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-white"></label>
                </div>
            </section>

            <section class="border-t border-gray-200 pt-6 dark:border-gray-800">
                <div class="mb-3 flex items-center justify-between gap-3">
                    <div><h5 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Период действия') }}</h5><p class="text-xs text-gray-500">{{ __('Дни и время, когда абонемент можно использовать.') }}</p></div>
                </div>
                <div id="validity-periods" class="tariff-period-list space-y-3">
                    @foreach($periods as $periodIndex => $period)
                        <div class="tariff-validity-period" data-validity-period>
                            <label class="text-xs text-gray-500">{{ __('С') }}<select name="validity_periods[{{ $periodIndex }}][start_day]" required class="mt-1 w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800">@foreach($weekdays as $day => $dayName)<option value="{{ $day }}" @selected(($period['start_day'] ?? 'mon') === $day)>{{ $dayName }}</option>@endforeach</select></label>
                            <label class="text-xs text-gray-500">{{ __('Время') }}<input name="validity_periods[{{ $periodIndex }}][start_time]" type="time" value="{{ $period['start_time'] ?? '00:00' }}" required class="mt-1 w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800"></label>
                            <label class="text-xs text-gray-500">{{ __('До') }}<select name="validity_periods[{{ $periodIndex }}][end_day]" required class="mt-1 w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800">@foreach($weekdays as $day => $dayName)<option value="{{ $day }}" @selected(($period['end_day'] ?? 'sun') === $day)>{{ $dayName }}</option>@endforeach</select></label>
                            <label class="text-xs text-gray-500">{{ __('Время') }}<input name="validity_periods[{{ $periodIndex }}][end_time]" type="time" value="{{ $period['end_time'] ?? '23:59' }}" required class="mt-1 w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800"></label>
                            <button type="button" data-add-validity-period class="brand-period-add mb-1 grid h-9 w-9 place-items-center rounded-lg" aria-label="{{ __('Добавить период') }}"><i class="fas fa-plus"></i></button>
                            <button type="button" data-remove-period class="mb-1 grid h-9 w-9 place-items-center rounded-lg text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/20" aria-label="{{ __('Удалить период') }}"><i class="fas fa-trash"></i></button>
                        </div>
                    @endforeach
                </div>
                <template id="validity-period-template">
                    <div class="tariff-validity-period" data-validity-period>
                        <label class="text-xs text-gray-500">{{ __('С') }}<select name="validity_periods[__INDEX__][start_day]" required class="mt-1 w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800">@foreach($weekdays as $day => $dayName)<option value="{{ $day }}">{{ $dayName }}</option>@endforeach</select></label>
                        <label class="text-xs text-gray-500">{{ __('Время') }}<input name="validity_periods[__INDEX__][start_time]" type="time" value="00:00" required class="mt-1 w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800"></label>
                        <label class="text-xs text-gray-500">{{ __('До') }}<select name="validity_periods[__INDEX__][end_day]" required class="mt-1 w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800">@foreach($weekdays as $day => $dayName)<option value="{{ $day }}">{{ $dayName }}</option>@endforeach</select></label>
                        <label class="text-xs text-gray-500">{{ __('Время') }}<input name="validity_periods[__INDEX__][end_time]" type="time" value="23:59" required class="mt-1 w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800"></label>
                        <button type="button" data-add-validity-period class="brand-period-add mb-1 grid h-9 w-9 place-items-center rounded-lg" aria-label="{{ __('Добавить период') }}"><i class="fas fa-plus"></i></button>
                        <button type="button" data-remove-period class="mb-1 grid h-9 w-9 place-items-center rounded-lg text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/20" aria-label="{{ __('Удалить период') }}"><i class="fas fa-trash"></i></button>
                    </div>
                </template>
            </section>

            <div class="flex flex-wrap items-center justify-between gap-4 border-t border-gray-200 pt-5 dark:border-gray-800">
                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300"><input type="checkbox" name="is_active" value="1" @checked($value('is_active', '1') === '1') class="brand-check rounded border-gray-300">{{ __('Активен') }}</label>
                <button type="submit" class="brand-button min-h-10 px-5">@if($editing !== null)<i class="fas fa-check"></i>{{ __('Сохранить тариф') }}@else<i class="fas fa-plus"></i>{{ __('Создать тариф') }}@endif</button>
            </div>
        </form>
            </div>
        </div>
    </dialog>

    <section class="mb-8">
        <div class="mb-3 flex flex-wrap items-baseline justify-between gap-3">
            <h4 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Поминутная оплата по зонам') }}</h4>
            <a href="{{ route('club-map.index') }}" class="text-sm font-medium text-green-700 hover:underline dark:text-green-400">{{ __('Изменить цену часа в зонах') }}</a>
        </div>
        <p class="mb-3 text-sm text-gray-600 dark:text-gray-400">{{ __('В агенте клиент покупает любое число минут по цене часа своей зоны. Без цены часа в зоне продаются только тарифы.') }}</p>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[480px] text-left text-sm">
                <thead class="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-gray-800 dark:text-gray-400">
                    <tr><th class="px-3 py-3 font-semibold">{{ __('Зона') }}</th><th class="px-3 py-3 font-semibold">{{ __('Цена за час') }}</th><th class="px-3 py-3 font-semibold">{{ __('Цена за минуту') }}</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($zones as $zone)
                        <tr>
                            <td class="px-3 py-3 font-medium text-gray-900 dark:text-white">{{ $zone->name }}</td>
                            @if ($zone->price_per_hour !== null)
                                <td class="whitespace-nowrap px-3 py-3 font-semibold text-gray-900 dark:text-white">{{ number_format((float) $zone->price_per_hour, 2, ',', ' ') }} {{ $currency }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-gray-700 dark:text-gray-300">{{ number_format((float) $zone->price_per_hour / 60, 2, ',', ' ') }} {{ $currency }}</td>
                            @else
                                <td colspan="2" class="px-3 py-3 text-gray-500 dark:text-gray-400">{{ __('Не продаётся поминутно') }}</td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="3" class="px-3 py-8 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('Добавьте ПК в зону на карте клуба.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section>
        <div class="mb-3 flex items-baseline justify-between gap-3">
            <h4 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Список тарифов') }}</h4>
            <span class="text-xs text-gray-500 dark:text-gray-400">{{ $tariffs->count() }}</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[600px] text-left text-sm">
                <thead class="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-gray-800 dark:text-gray-400">
                    <tr><th class="px-3 py-3 font-semibold">{{ __('Название') }}</th><th class="px-3 py-3 font-semibold">{{ __('Зоны') }}</th><th class="px-3 py-3 font-semibold">{{ __('Длительность') }}</th><th class="px-3 py-3 font-semibold">{{ __('Цена') }}</th><th class="px-3 py-3 font-semibold">{{ __('Статус') }}</th><th class="px-3 py-3"><span class="sr-only">{{ __('Действия') }}</span></th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($tariffs as $tariff)
                        <tr>
                            <td class="px-3 py-3 font-medium text-gray-900 dark:text-white">{{ $tariff->name }}<span class="mt-1 block text-xs font-normal text-gray-500 dark:text-gray-400">{{ $tariff->club_name }}@if($tariff->discount_enabled) · {{ __('Со скидкой') }}@endif</span>@if($tariff->sale_starts_at?->isFuture())<span class="mt-1 block text-xs font-normal text-amber-600 dark:text-amber-400">{{ __('Продажа с :date', ['date' => $tariff->sale_starts_at->format('d.m.Y H:i')]) }}</span>@elseif($tariff->sale_ends_at !== null)<span class="mt-1 block text-xs font-normal {{ $tariff->sale_ends_at->isFuture() ? 'text-gray-500 dark:text-gray-400' : 'text-red-600 dark:text-red-400' }}">{{ $tariff->sale_ends_at->isFuture() ? __('Продажа до :date', ['date' => $tariff->sale_ends_at->format('d.m.Y H:i')]) : __('Продажа закончилась :date', ['date' => $tariff->sale_ends_at->format('d.m.Y H:i')]) }}</span>@endif</td>
                            <td class="px-3 py-3 text-gray-700 dark:text-gray-300">{{ $tariff->zones->pluck('name')->join(', ') ?: __('Все зоны') }}</td>
                            <td class="whitespace-nowrap px-3 py-3 text-gray-700 dark:text-gray-300">{{ __(':minutes мин.', ['minutes' => $tariff->duration_minutes]) }}</td>
                            <td class="whitespace-nowrap px-3 py-3 font-semibold text-gray-900 dark:text-white">{{ number_format((float) $tariff->price, 2, ',', ' ') }} {{ $tariff->currency }}</td>
                            <td class="px-3 py-3"><span class="rounded-full px-2 py-1 text-xs font-medium {{ $tariff->is_active ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300' : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400' }}">{{ $tariff->is_active ? __('Активен') : __('Выключен') }}</span></td>
                            <td class="whitespace-nowrap px-3 py-3 text-right"><a href="{{ route('tariffs.edit', $tariff) }}" class="text-sm font-medium text-green-700 hover:underline dark:text-green-400">{{ __('Изменить') }}</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-10 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('Тарифов пока нет.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
@endsection

@push('scripts')
<script>
(()=>{const dialog=document.getElementById('tariff-dialog'),list=document.getElementById('validity-periods'),template=document.getElementById('validity-period-template');document.getElementById('open-tariff-dialog').addEventListener('click',()=>dialog.showModal());document.querySelectorAll('[data-close-tariff-dialog]').forEach(button=>button.addEventListener('click',()=>dialog.close()));dialog.addEventListener('click',event=>{if(event.target===dialog)dialog.close()});if(dialog.hasAttribute('data-open-on-load'))dialog.showModal();dialog.addEventListener('close',()=>{if(dialog.dataset.closeHref)location.href=dialog.dataset.closeHref});let nextIndex=list.querySelectorAll('[data-validity-period]').length;list.addEventListener('click',event=>{const add=event.target.closest('[data-add-validity-period]');if(add){list.insertAdjacentHTML('beforeend',template.innerHTML.replaceAll('__INDEX__',String(nextIndex++)));return}const remove=event.target.closest('[data-remove-period]');if(remove&&list.querySelectorAll('[data-validity-period]').length>1)remove.closest('[data-validity-period]').remove()})})();
</script>
@endpush