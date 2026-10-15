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
                <div>
                    <label for="reservation-phone" class="{{ $label }}">{{ __('Телефон клиента') }}</label>
                    <input id="reservation-phone" name="customer_phone" type="tel" inputmode="tel" maxlength="20" placeholder="+7 7XX XXX XX XX" value="{{ old('customer_phone') }}" class="{{ $field }}">
                </div>
                <div>
                    <label for="reservation-name" class="{{ $label }}">{{ __('Имя клиента') }}</label>
                    <input id="reservation-name" name="customer_name" maxlength="120" value="{{ old('customer_name') }}" class="{{ $field }}">
                </div>
                <div class="md:col-span-2">
                    <label for="reservation-note" class="{{ $label }}">{{ __('Комментарий') }}</label>
                    <input id="reservation-note" name="note" maxlength="500" value="{{ old('note') }}" class="{{ $field }}">
                </div>
                <div><button type="submit" class="brand-button w-full justify-center"><i class="fas fa-calendar-plus"></i>{{ __('Забронировать') }}</button></div>
            </form>
            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('Если телефон принадлежит клиенту клуба, он сам сможет купить тариф на этом ПК во время брони. Иначе, когда гость придёт, нажмите «Клиент пришёл».') }}</p>
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
                            @if($reservation->user_id)<div class="text-xs text-green-700 dark:text-green-400">{{ __('Клиент клуба') }}</div>@endif
                            @if($reservation->note)<div class="text-xs text-gray-500">{{ $reservation->note }}</div>@endif
                        </td>
                        <td class="px-4 py-3"><span class="inline-flex rounded-full px-2 py-1 text-xs font-medium {{ $stateClass }}">{{ $stateLabel }}</span></td>
                        <td class="whitespace-nowrap px-4 py-3 text-right">
                            @if(in_array($reservation->state(), ['pending', 'active'], true))
                                <form method="POST" action="{{ route('reservations.arrive', $reservation) }}" class="inline">@csrf<button type="submit" class="rounded-lg border border-green-300 px-3 py-1.5 text-xs font-medium text-green-700 dark:border-green-800 dark:text-green-400">{{ __('Клиент пришёл') }}</button></form>
                                <form method="POST" action="{{ route('reservations.cancel', $reservation) }}" class="inline" onsubmit="return confirm(@js(__('Отменить бронь?')))">@csrf<button type="submit" class="rounded-lg border border-red-300 px-3 py-1.5 text-xs font-medium text-red-700 dark:border-red-800 dark:text-red-400">{{ __('Отменить') }}</button></form>
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
