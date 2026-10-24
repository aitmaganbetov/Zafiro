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
        $alwaysPeriods = [['start_day' => 'mon', 'start_time' => '00:00', 'end_day' => 'sun', 'end_time' => '23:59']];
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
            </div>

            <div class="grid grid-cols-1 gap-4 border-t border-gray-200 pt-6 dark:border-gray-800 md:grid-cols-2">
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

            {{-- Sale time: outside these days and times the tariff is not shown or sold. --}}
            <section class="border-t border-gray-200 pt-6 dark:border-gray-800">
                <div class="mb-3"><h5 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Время продажи') }}</h5><p class="text-xs text-gray-500">{{ __('Дни и время, когда тариф продаётся в агенте и на кассе. В другое время он не показывается.') }}</p></div>
                @include('tariffs.periods', ['field' => 'sale_periods', 'periods' => $value('sale_periods', $alwaysPeriods)])
            </section>

            <section class="border-t border-gray-200 pt-6 dark:border-gray-800">
                <div class="mb-3"><h5 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Период действия') }}</h5><p class="text-xs text-gray-500">{{ __('Дни и время, когда абонемент можно использовать.') }}</p></div>
                @include('tariffs.periods', ['field' => 'validity_periods', 'periods' => $value('validity_periods', $alwaysPeriods)])
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
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <h4 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Список тарифов') }} <span class="text-xs font-normal text-gray-500 dark:text-gray-400">{{ $tariffs->count() }}</span></h4>
            {{-- The tariffs that work in one zone: its own and those of every zone. --}}
            <form method="GET" action="{{ route('tariffs.index') }}" class="flex items-center gap-2">
                <label for="tariff-zone-filter" class="text-sm text-gray-600 dark:text-gray-400">{{ __('Зона') }}</label>
                <select id="tariff-zone-filter" name="zone" onchange="this.form.submit()" class="rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-white">
                    <option value="">{{ __('Все зоны') }}</option>
                    @foreach($zones as $zone)<option value="{{ $zone->id }}" @selected($zoneFilter === $zone->id)>{{ $zone->name }}</option>@endforeach
                </select>
                <noscript><button type="submit" class="text-sm">{{ __('Показать') }}</button></noscript>
            </form>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[600px] text-left text-sm">
                <thead class="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-gray-800 dark:text-gray-400">
                    <tr><th class="px-3 py-3 font-semibold">{{ __('Название') }}</th><th class="px-3 py-3 font-semibold">{{ __('Зоны') }}</th><th class="px-3 py-3 font-semibold">{{ __('Длительность') }}</th><th class="px-3 py-3 font-semibold">{{ __('Цена') }}</th><th class="px-3 py-3 font-semibold">{{ __('Статус') }}</th><th class="px-3 py-3"><span class="sr-only">{{ __('Действия') }}</span></th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($tariffs as $tariff)
                        <tr>
                            <td class="px-3 py-3 font-medium text-gray-900 dark:text-white">{{ $tariff->name }}@if($tariff->discount_enabled)<span class="mt-1 block text-xs font-normal text-gray-500 dark:text-gray-400">{{ __('Со скидкой') }}</span>@endif {{-- Why the agent and the cash register do not offer it now (the list shows every tariff). --}}
@php($sold = (int) ($purchaseCounts[$tariff->id] ?? 0))
@if($tariff->quantity_limit_enabled && $tariff->quantity_limit !== null)<span class="mt-1 block text-xs font-normal {{ $sold >= $tariff->quantity_limit ? 'text-red-600 dark:text-red-400' : 'text-gray-500 dark:text-gray-400' }}">{{ $sold >= $tariff->quantity_limit ? __('Продано :sold из :limit — лимит количества исчерпан, тариф не продаётся', ['sold' => $sold, 'limit' => $tariff->quantity_limit]) : __('Продано :sold из :limit', ['sold' => $sold, 'limit' => $tariff->quantity_limit]) }}</span>@endif
@unless($tariff->onSale())<span class="mt-1 block text-xs font-normal text-amber-600 dark:text-amber-400">{{ __('Сейчас не продаётся: вне времени продажи') }}</span>@endunless
@unless($tariff->inValidityPeriod())<span class="mt-1 block text-xs font-normal text-amber-600 dark:text-amber-400">{{ __('Сейчас не продаётся: вне периода действия') }}</span>@endunless</td>
                            <td class="px-3 py-3 text-gray-700 dark:text-gray-300">{{ $tariff->zones->pluck('name')->join(', ') ?: __('Все зоны') }}</td>
                            <td class="whitespace-nowrap px-3 py-3 text-gray-700 dark:text-gray-300">{{ __(':minutes мин.', ['minutes' => $tariff->duration_minutes]) }}</td>
                            <td class="whitespace-nowrap px-3 py-3 font-semibold text-gray-900 dark:text-white">{{ number_format((float) $tariff->price, 2, ',', ' ') }} {{ $tariff->currency }}</td>
                            <td class="px-3 py-3"><span class="rounded-full px-2 py-1 text-xs font-medium {{ $tariff->is_active ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300' : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400' }}">{{ $tariff->is_active ? __('Активен') : __('Выключен') }}</span></td>
                            <td class="whitespace-nowrap px-3 py-3 text-right"><a href="{{ route('tariffs.edit', $tariff) }}" class="text-sm font-medium text-green-700 hover:underline dark:text-green-400">{{ __('Изменить') }}</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-10 text-center text-sm text-gray-500 dark:text-gray-400">{{ $zoneFilter !== null ? __('В этой зоне тарифов нет.') : __('Тарифов пока нет.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
@endsection

@push('scripts')
<script>
(()=>{const dialog=document.getElementById('tariff-dialog');document.getElementById('open-tariff-dialog').addEventListener('click',()=>dialog.showModal());document.querySelectorAll('[data-close-tariff-dialog]').forEach(button=>button.addEventListener('click',()=>dialog.close()));dialog.addEventListener('click',event=>{if(event.target===dialog)dialog.close()});if(dialog.hasAttribute('data-open-on-load'))dialog.showModal();dialog.addEventListener('close',()=>{if(dialog.dataset.closeHref)location.href=dialog.dataset.closeHref});document.querySelectorAll('[data-period-list]').forEach(list=>{const template=list.nextElementSibling;let nextIndex=list.querySelectorAll('[data-period]').length;list.addEventListener('click',event=>{const add=event.target.closest('[data-add-period]');if(add){const rows=list.querySelectorAll('[data-period]'),last=rows[rows.length-1];list.insertAdjacentHTML('beforeend',template.innerHTML.replaceAll('__INDEX__',String(nextIndex++)));
// The new row is the last one a day later (Mon 10:00–17:00 → Tue 10:00–17:00), not "until Monday".
const row=list.lastElementChild,days=['mon','tue','wed','thu','fri','sat','sun'],field=(element,part)=>element.querySelector(`[name$="[${part}]"]`),next=day=>days[(days.indexOf(day)+1)%7];if(last){field(row,'start_day').value=next(field(last,'start_day').value);field(row,'end_day').value=next(field(last,'end_day').value);field(row,'start_time').value=field(last,'start_time').value;field(row,'end_time').value=field(last,'end_time').value}return}const remove=event.target.closest('[data-remove-period]');if(remove&&list.querySelectorAll('[data-period]').length>1)remove.closest('[data-period]').remove()})})})();
</script>
@endpush