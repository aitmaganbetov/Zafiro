@extends('layouts.landing')

@section('title', __('Приложение Zafiro для сотрудников — касса, карта клуба и заказы в телефоне'))
@section('description', __('Мобильное приложение Zafiro для сотрудников клуба: карта ПК, касса и смены, клиенты, заказы из агента, чат, склад и отчёт за день.'))

@section('content')
@php
    $features = [
        ['fa-map-location-dot', __('Карта клуба'), [__('ПК по зонам и статусам, обновление каждые 10 секунд'), __('Включить, перезагрузить, выключить, завершить сессию'), __('Бронь и время гостю — сразу на несколько ПК')]],
        ['fa-cash-register', __('Касса'), [__('Открытие и закрытие смены, X- и Z-отчёт'), __('Пополнение баланса и продажа товаров'), __('Приход и расход, последние операции')]],
        ['fa-users', __('Клиенты'), [__('Поиск по имени и телефону'), __('Баланс, бонусы и скидка клиента'), __('Пополнение прямо из карточки')]],
        ['fa-bag-shopping', __('Заказы и чат'), [__('Заказы из магазина агента с номером ПК'), __('«Выдан» или «Отменить» одной кнопкой'), __('Ответы гостям в чат клуба')]],
        ['fa-boxes-stacked', __('Склад'), [__('Остатки товаров'), __('Приход товара'), __('Списание — у кого есть право')]],
        ['fa-chart-line', __('Отчёт за день'), [__('Доход и расход за сегодня и за 7 дней'), __('ПК в сети и в брони'), __('Товары, которых осталось мало')]],
    ];
    $access = [
        ['fa-user-shield', __('Права как в панели'), __('Сотрудник видит в приложении только те разделы и кнопки, на которые у него есть права в клубе.')],
        ['fa-key', __('Вход и пароль'), __('Вход по номеру и паролю сотрудника, «Забыли пароль?» — код приходит в Telegram.')],
        ['fa-building', __('Несколько клубов'), __('Если человек работает в нескольких клубах, он выбирает клуб после входа.')],
        ['fa-bell', __('Уведомления'), __('Колокольчик в шапке: новые заказы, сообщения, ПК не в сети, мало товара.')],
    ];
    $tiles = ['free', 'busy', 'busy', 'guest', 'free', 'busy', 'staff', 'busy', 'free', 'busy', 'busy', 'booked'];
    $tileColors = ['free' => 'bg-emerald-50 text-emerald-700 ring-emerald-300', 'busy' => 'bg-[#ff0169]/10 text-[#d90058] ring-[#ff0169]/40', 'guest' => 'bg-sky-50 text-sky-700 ring-sky-300', 'staff' => 'bg-red-50 text-red-700 ring-red-300', 'booked' => 'bg-amber-50 text-amber-700 ring-amber-300'];
@endphp
<section class="mx-auto grid max-w-6xl items-center gap-12 px-4 pb-16 pt-14 sm:px-6 lg:grid-cols-[1.2fr_1fr] lg:pt-20">
    <div>
        <p class="text-sm font-semibold uppercase tracking-[0.2em] text-[#e6005e]">{{ __('Продукты') }}</p>
        <h1 class="mt-3 bg-gradient-to-r from-gray-900 via-gray-900 to-[#ff0169] bg-clip-text text-4xl font-extrabold leading-tight text-transparent sm:text-5xl">{{ __('Приложение для сотрудников') }}</h1>
        <p class="mt-6 max-w-xl text-lg text-gray-600">{{ __('Касса, карта клуба и заказы в телефоне администратора: можно работать, не стоя за стойкой.') }}</p>
        <dl class="mt-10 grid max-w-lg grid-cols-3 gap-4 text-center sm:text-left">
            <div><dt class="text-sm text-gray-500">{{ __('Платформа') }}</dt><dd class="text-xl font-bold">Android</dd></div>
            <div><dt class="text-sm text-gray-500">{{ __('Для кого') }}</dt><dd class="text-xl font-bold">{{ __('5 ролей') }}</dd></div>
            <div><dt class="text-sm text-gray-500">{{ __('Тема') }}</dt><dd class="text-xl font-bold">{{ __('день и ночь') }}</dd></div>
        </dl>
        <p class="mt-8 max-w-xl rounded-2xl border border-gray-200 bg-gray-50 p-4 text-sm text-gray-600"><i class="fab fa-android mr-2 text-[#e6005e]" aria-hidden="true"></i>{{ __('Для владельца, администратора, менеджера, кассира и оператора. Как установить приложение — уточните в поддержке Zafiro.') }}</p>
    </div>

    {{-- A stylised phone with the club map. --}}
    <div class="relative mx-auto w-full max-w-[300px]" aria-hidden="true">
        <div class="absolute -inset-6 rounded-[3rem] bg-gradient-to-br from-[#ff0169]/20 to-transparent blur-2xl"></div>
        <div class="relative rounded-[2.5rem] border-[10px] border-gray-900 bg-white p-4 shadow-2xl">
            <div class="mx-auto mb-4 h-1.5 w-16 rounded-full bg-gray-200"></div>
            <div class="flex items-center justify-between">
                <p class="font-bold">{{ __('Карта клуба') }}</p>
                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] text-gray-500">{{ __('Смена открыта') }}</span>
            </div>
            <p class="mt-3 text-[10px] font-semibold uppercase tracking-wider text-gray-400">Bootcamp</p>
            <div class="mt-2 grid grid-cols-4 gap-2">
                @foreach($tiles as $state)
                    <span class="flex h-11 items-center justify-center rounded-xl text-sm font-bold ring-1 {{ $tileColors[$state] }}">{{ $loop->iteration }}</span>
                @endforeach
            </div>
            <div class="mt-4 grid grid-cols-3 gap-2 text-center text-[10px] font-semibold">
                <span class="flex flex-col items-center gap-1 rounded-xl bg-gray-100 py-2"><i class="fas fa-rotate-right text-sm text-gray-600"></i>{{ __('Перезагрузить') }}</span>
                <span class="flex flex-col items-center gap-1 rounded-xl bg-gray-100 py-2"><i class="fas fa-power-off text-sm text-gray-600"></i>{{ __('Выключить') }}</span>
                <span class="flex flex-col items-center gap-1 rounded-xl bg-[#ff0169] py-2 text-white"><i class="fas fa-stopwatch text-sm"></i>{{ __('Время гостю') }}</span>
            </div>
        </div>
    </div>
</section>

<section class="border-t border-gray-100 py-16">
    <div class="mx-auto max-w-6xl px-4 sm:px-6">
        <p class="text-sm font-semibold uppercase tracking-[0.2em] text-[#e6005e]">{{ __('Возможности') }}</p>
        <h2 class="mt-3 max-w-2xl text-3xl font-bold">{{ __('Главное из панели клуба — в кармане') }}</h2>
        <div class="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            @foreach($features as [$icon, $title, $points])
                <article class="rounded-2xl border border-gray-200 bg-gray-50 p-6">
                    <span class="mb-5 flex h-12 w-12 items-center justify-center rounded-xl bg-[#ff0169]/15 text-xl text-[#e6005e]"><i class="fas {{ $icon }}" aria-hidden="true"></i></span>
                    <h3 class="text-lg font-semibold">{{ $title }}</h3>
                    <ul class="mt-3 space-y-2 text-sm leading-relaxed text-gray-600">
                        @foreach($points as $point)
                            <li class="flex gap-2"><i class="fas fa-check mt-1 text-xs text-[#e6005e]" aria-hidden="true"></i><span>{{ $point }}</span></li>
                        @endforeach
                    </ul>
                </article>
            @endforeach
        </div>
    </div>
</section>

<section class="border-t border-gray-100 py-16">
    <div class="mx-auto max-w-6xl px-4 sm:px-6">
        <p class="text-sm font-semibold uppercase tracking-[0.2em] text-[#e6005e]">{{ __('Доступ') }}</p>
        <h2 class="mt-3 max-w-2xl text-3xl font-bold">{{ __('Каждому — своё') }}</h2>
        <div class="mt-10 grid gap-5 md:grid-cols-2">
            @foreach($access as [$icon, $title, $text])
                <article class="flex gap-5 rounded-2xl border border-gray-200 bg-gray-50 p-6">
                    <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-[#ff0169]/15 text-xl text-[#e6005e]"><i class="fas {{ $icon }}" aria-hidden="true"></i></span>
                    <div><h3 class="text-lg font-semibold">{{ $title }}</h3><p class="mt-2 text-sm leading-relaxed text-gray-600">{{ $text }}</p></div>
                </article>
            @endforeach
        </div>
    </div>
</section>
@endsection
