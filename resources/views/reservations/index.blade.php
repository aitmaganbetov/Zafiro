@extends('layouts.admin')

@section('page-title', __('Брони'))

@section('content')
@php
    $field = 'w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-white';
    $label = 'mb-2 block text-xs font-medium text-gray-600 dark:text-gray-400';
    $states = [
        'pending' => [__('Ожидается'), 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300'],
        'active' => [__('Идёт'), 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300'],
        'arrived' => [__('Клиент пришёл'), 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300'],
        'finished' => [__('Завершена'), 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300'],
        'cancelled' => [__('Отменена'), 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300'],
    ];
@endphp
<div class="p-6 lg:p-8">
    <header class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h3 class="mb-2 text-2xl font-bold text-gray-900 dark:text-white">{{ __('Брони') }}</h3>
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('Забронированный ПК в это время не продаёт тарифы другим клиентам. Между бронями и сессиями остаётся :count мин (настройка «Интервал между бронированиями»).', ['count' => $gapMinutes]) }}</p>
        </div>
    </header>

    @if(session('status'))<div class="mb-5 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="mb-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300" role="alert">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif

    <section class="mb-6 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900" aria-labelledby="new-reservation-title">
        <h4 id="new-reservation-title" class="mb-4 font-semibold text-gray-900 dark:text-white">{{ __('Новая бронь') }}</h4>
        @if($computers->isEmpty())
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('В клубе ещё нет ПК. Добавьте их в разделе «Зоны».') }}</p>
        @else
            @if($currentMembership->hasPermission('reservations.create'))
            <form method="POST" action="{{ route('reservations.store') }}" class="grid grid-cols-1 items-end gap-3 md:grid-cols-2 xl:grid-cols-4">
                @csrf
                <div>
                    <label for="reservation-computer" class="{{ $label }}">{{ __('ПК') }}</label>
                    <select id="reservation-computer" name="club_computer_id" required class="{{ $field }}">
                        @foreach($computers->groupBy(fn ($computer) => $computer->zone?->name ?? __('Без зоны')) as $zoneName => $zoneComputers)
                            <optgroup label="{{ $zoneName }}">
                                @foreach($zoneComputers as $computer)<option value="{{ $computer->id }}" @selected((string) old('club_computer_id') === (string) $computer->id)>{{ $computer->displayName() }} (#{{ $computer->number }})</option>@endforeach
                            </optgroup>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="reservation-start" class="{{ $label }}">{{ __('Начало') }}</label>
                    <input id="reservation-start" name="starts_at" type="datetime-local" required value="{{ old('starts_at', $defaultStart->format('Y-m-d\TH:i')) }}" class="{{ $field }}">
                </div>
                <div>
                    <label for="reservation-duration" class="{{ $label }}">{{ __('Длительность') }}</label>
                    <select id="reservation-duration" name="duration_minutes" required class="{{ $field }}">
                        @foreach([30, 60, 90, 120, 180, 240, 360, 480, 720] as $minutes)
                            <option value="{{ $minutes }}" @selected((int) old('duration_minutes', 60) === $minutes)>{{ $minutes < 60 ? trans_choice(':count минута|:count минуты|:count минут', $minutes) : trans_choice(':count час|:count часа|:count часов', intdiv($minutes, 60)).($minutes % 60 ? ' '.trans_choice(':count минута|:count минуты|:count минут', $minutes % 60) : '') }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pos-user-picker" data-customer-picker data-url="{{ route('reservations.customers') }}">
                    <label for="reservation-customer" class="{{ $label }}">{{ __('Клиент') }}</label>
                    <input type="hidden" name="user_id" value="{{ old('user_id') }}" data-customer-id>
                    <input type="hidden" name="customer_label" value="{{ old('customer_label') }}" data-customer-label>
                    <input id="reservation-customer" type="search" role="combobox" aria-autocomplete="list" aria-controls="reservation-customer-options" aria-expanded="false" autocomplete="off" required value="{{ old('customer_label') }}" placeholder="{{ __('Поиск по имени или телефону') }}" class="{{ $field }}" data-customer-search>
                    <div id="reservation-customer-options" class="pos-user-options" role="listbox" hidden data-customer-options></div>
                </div>
                <div class="md:col-span-2">
                    <label for="reservation-note" class="{{ $label }}">{{ __('Комментарий') }}</label>
                    <input id="reservation-note" name="note" maxlength="500" value="{{ old('note') }}" class="{{ $field }}">
                </div>
                <div><button type="submit" class="brand-button w-full justify-center"><i class="fas fa-calendar-plus"></i>{{ __('Забронировать') }}</button></div>
            </form>
            @endif
            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('Во время брони только этот клиент может купить тариф на этом ПК. Нет в списке — добавьте его в разделе «Пользователи».') }}</p>
        @endif
    </section>

    <form method="GET" action="{{ route('reservations.index') }}" class="mb-4 flex flex-wrap items-end gap-3">
        <div>
            <label for="reservation-day" class="{{ $label }}">{{ __('День') }}</label>
            <input id="reservation-day" name="date" type="date" value="{{ $day->format('Y-m-d') }}" class="{{ $field }}">
        </div>
        <button type="submit" class="brand-button"><i class="fas fa-filter"></i>{{ __('Показать') }}</button>
        <a href="{{ route('reservations.index', ['date' => $day->copy()->subDay()->format('Y-m-d')]) }}" class="rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-700 dark:border-gray-700 dark:text-gray-300" aria-label="{{ __('Предыдущий день') }}"><i class="fas fa-chevron-left"></i></a>
        <a href="{{ route('reservations.index', ['date' => $day->copy()->addDay()->format('Y-m-d')]) }}" class="rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-700 dark:border-gray-700 dark:text-gray-300" aria-label="{{ __('Следующий день') }}"><i class="fas fa-chevron-right"></i></a>
    </form>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
        <table class="w-full min-w-[850px] text-left text-sm">
            <thead class="border-b border-gray-200 bg-gray-50 text-xs uppercase text-gray-500 dark:border-gray-800 dark:bg-gray-950 dark:text-gray-400">
                <tr><th class="px-4 py-3 font-semibold">{{ __('Время') }}</th><th class="px-4 py-3 font-semibold">{{ __('ПК') }}</th><th class="px-4 py-3 font-semibold">{{ __('Клиент') }}</th><th class="px-4 py-3 font-semibold">{{ __('Статус') }}</th><th class="px-4 py-3 font-semibold"><span class="sr-only">{{ __('Действия') }}</span></th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse($reservations as $reservation)
                    @php([$stateLabel, $stateClass] = $states[$reservation->state()])
                    <tr>
                        <td class="whitespace-nowrap px-4 py-3 text-gray-800 dark:text-gray-200">{{ $reservation->starts_at->format('H:i') }} – {{ $reservation->ends_at->format($reservation->ends_at->isSameDay($reservation->starts_at) ? 'H:i' : 'd.m H:i') }}</td>
                        <td class="px-4 py-3 text-gray-800 dark:text-gray-200">{{ $reservation->computer?->displayName() ?? '—' }}@if($reservation->computer?->zone)<div class="text-xs text-gray-500">{{ $reservation->computer->zone->name }}</div>@endif</td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $reservation->customerLabel() ?: '—' }}
                            @if($reservation->note)<div class="text-xs text-gray-500">{{ $reservation->note }}</div>@endif
                        </td>
                        <td class="px-4 py-3"><span class="inline-flex rounded-full px-2 py-1 text-xs font-medium {{ $stateClass }}">{{ $stateLabel }}</span></td>
                        <td class="whitespace-nowrap px-4 py-3 text-right">
                            @if(in_array($reservation->state(), ['pending', 'active'], true))
                                @if($currentMembership->hasPermission('reservations.update'))<form method="POST" action="{{ route('reservations.arrive', $reservation) }}" class="inline">@csrf<button type="submit" class="rounded-lg border border-green-300 px-3 py-1.5 text-xs font-medium text-green-700 dark:border-green-800 dark:text-green-400">{{ __('Клиент пришёл') }}</button></form>@endif
                                @if($currentMembership->hasPermission('reservations.delete'))<form method="POST" action="{{ route('reservations.cancel', $reservation) }}" class="inline" onsubmit="return confirm(@js(__('Отменить бронь?')))">@csrf<button type="submit" class="rounded-lg border border-red-300 px-3 py-1.5 text-xs font-medium text-red-700 dark:border-red-800 dark:text-red-400">{{ __('Отменить') }}</button></form>@endif
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-12 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('На этот день броней нет.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $reservations->links() }}</div>
</div>
@endsection

@push('scripts')
<script>
(() => {
    const picker = document.querySelector('[data-customer-picker]');
    if (!picker) return;
    const search = picker.querySelector('[data-customer-search]');
    const userId = picker.querySelector('[data-customer-id]');
    const label = picker.querySelector('[data-customer-label]');
    const list = picker.querySelector('[data-customer-options]');
    const texts = {notFound: @json(__('Пользователь не найден')), pick: @json(__('Выберите клиента из пользователей клуба.'))};
    let timer = null;
    let request = null;

    const close = () => { list.hidden = true; search.setAttribute('aria-expanded', 'false'); };
    const render = (customers) => {
        list.replaceChildren();
        if (customers.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'pos-user-no-results';
            empty.textContent = texts.notFound;
            list.append(empty);
        }
        customers.forEach((customer) => {
            const option = document.createElement('button');
            option.type = 'button';
            option.className = 'pos-user-option';
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', String(String(customer.id) === userId.value));
            option.dataset.id = customer.id;
            option.dataset.label = `${customer.name} · ${customer.phone ?? ''}`;
            const name = document.createElement('strong');
            name.textContent = customer.name;
            const phone = document.createElement('small');
            phone.textContent = customer.phone ?? '';
            option.append(name, phone);
            list.append(option);
        });
        list.hidden = false;
        search.setAttribute('aria-expanded', 'true');
    };
    const find = async () => {
        const query = search.value.trim();
        if (query.length < 2) { close(); return; }
        request?.abort();
        request = new AbortController();
        try {
            const response = await fetch(`${picker.dataset.url}?${new URLSearchParams({q: query})}`, {headers: {Accept: 'application/json'}, signal: request.signal});
            if (response.ok) render((await response.json()).data);
        } catch (error) {
            if (error.name !== 'AbortError') close();
        }
    };

    search.addEventListener('input', () => {
        userId.value = '';
        label.value = '';
        search.setCustomValidity('');
        clearTimeout(timer);
        timer = setTimeout(find, 250);
    });
    list.addEventListener('click', (event) => {
        const option = event.target.closest('.pos-user-option');
        if (!option) return;
        userId.value = option.dataset.id;
        label.value = option.dataset.label;
        search.value = option.dataset.label;
        search.setCustomValidity('');
        close();
    });
    document.addEventListener('click', (event) => { if (!picker.contains(event.target)) close(); });
    picker.closest('form').addEventListener('submit', (event) => {
        if (userId.value) return;
        event.preventDefault();
        search.setCustomValidity(texts.pick);
        search.reportValidity();
    });
})();
</script>
@endpush
