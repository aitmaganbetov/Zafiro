@extends('layouts.landing')

@section('title', __('Zafiro — система управления компьютерным клубом'))
@section('description', __('Касса, карта зала, брони, тарифы, магазин и агент на ПК в одной системе. Подключите свой компьютерный клуб за один вечер.'))

@section('content')
@php
    $features = [
        ['fa-map-location-dot', __('Карта зала'), __('Зоны и ПК на схеме клуба: кто играет, сколько осталось, что забронировано и где идёт обслуживание.')],
        ['fa-cash-register', __('Касса и смены'), __('Продажи, пополнения балансов, X- и Z-отчёты с разбивкой по товарам и сотрудникам, печать на 80-мм принтер.')],
        ['fa-desktop', __('Агент на ПК'), __('Блокирует ПК до оплаты, показывает время и баланс, сам выключает или перезагружает ПК после сессии.')],
        ['fa-calendar-check', __('Брони'), __('Бронирование ПК на время для клиента: с карты зала или из списка, без накладок.')],
        ['fa-stopwatch', __('Тарифы и поминутно'), __('Пакеты часов и оплата по минутам по цене часа зоны, бонусный и основной счёт.')],
        ['fa-bag-shopping', __('Магазин в агенте'), __('Клиент заказывает напитки и еду с ПК и платит с баланса, кассир видит заказ и несёт его к месту.')],
        ['fa-user-group', __('Гости и друзья'), __('Вход по QR-коду, регистрация прямо на ПК, друзья, переводы и пересадка на свободный ПК.')],
        ['fa-hard-drive', __('Бездисковые клубы'), __('Один раз настроили суперклиент — остальные ПК подключаются сами после загрузки образа.')],
        ['fa-plug', __('API и вебхуки'), __('Интеграция с вашими сервисами: ключи с правами, события продаж и склада.')],
    ];
    $steps = [
        [__('Зарегистрируйте клуб'), __(':days дней бесплатно, без карты и договора.', ['days' => $trialDays])],
        [__('Нарисуйте зал'), __('Создайте зоны, расставьте ПК на карте и задайте тарифы.')],
        [__('Установите агент'), __('Запустите установщик на ПК и введите ключ клуба — ПК появится на карте.')],
    ];
    $faq = [
        [__('Нужен ли свой сервер?'), __('Нет. Zafiro работает в облаке: панель открывается в браузере, а на ПК клуба ставится только агент.')],
        [__('Что если пропадёт интернет?'), __('Оплаченная сессия продолжается, а агент сам переподключится к серверу, когда связь вернётся.')],
        [__('Подходит для бездисковой системы?'), __('Да. Агент ставится в образ суперклиента, и каждый ПК после загрузки сам получает свой номер.')],
        [__('Клиент может обойти блокировку?'), __('Агент работает со службой Windows, закрывается только по PIN-коду сотрудника и сам запускается снова.')],
        [__('Сколько это стоит?'), __('Первые :days дней бесплатно. Стоимость подписки зависит от количества ПК — напишите нам.', ['days' => $trialDays])],
    ];
@endphp
    {{-- Hero --}}
    <section class="mx-auto grid max-w-6xl items-center gap-12 px-4 pb-20 pt-14 sm:px-6 lg:grid-cols-2 lg:pt-20">
        <div>
            <p class="mb-5 inline-flex items-center gap-2 rounded-full border border-[#ff0169]/40 bg-[#ff0169]/10 px-3 py-1 text-sm text-[#d90058]"><i class="fas fa-bolt" aria-hidden="true"></i>{{ __('Софт для компьютерных клубов') }}</p>
            <h1 class="text-4xl font-extrabold leading-tight text-gray-900 sm:text-5xl lg:text-6xl">{{ __('Весь клуб - в одном окне') }}</h1>
            <p class="mt-6 max-w-xl text-lg text-gray-600">{{ __('Касса, карта зала, брони, тарифы, магазин и агент на ПК в одной системе. Подключите свой компьютерный клуб за один вечер.') }}</p>
            <div class="mt-8 flex flex-wrap gap-3">
                @auth
                    <a href="{{ route('dashboard') }}" class="rounded-xl bg-[#ff0169] px-6 py-3 font-semibold shadow-xl shadow-[#ff0169]/30 hover:bg-[#e6005e] text-white">{{ __('Открыть панель') }}</a>
                @else
                    <a href="{{ route('auth.show-register') }}" class="rounded-xl bg-[#ff0169] px-6 py-3 font-semibold shadow-xl shadow-[#ff0169]/30 hover:bg-[#e6005e] text-white">{{ __(':days дней бесплатно', ['days' => $trialDays]) }}</a>
                @endauth
                <a href="{{ route('product.features') }}" class="rounded-xl border border-gray-300 px-6 py-3 font-semibold text-gray-800 hover:border-gray-400">{{ __('Посмотреть возможности') }}</a>
            </div>
            <dl class="mt-10 grid max-w-lg grid-cols-3 gap-4 text-center sm:text-left">
                <div><dt class="text-sm text-gray-500">{{ __('Запуск') }}</dt><dd class="text-xl font-bold">{{ __('1 вечер') }}</dd></div>
                <div><dt class="text-sm text-gray-500">{{ __('Оборудование') }}</dt><dd class="text-xl font-bold">{{ __('не нужно') }}</dd></div>
                <div><dt class="text-sm text-gray-500">{{ __('Языки') }}</dt><dd class="text-xl font-bold">RU · KZ · EN</dd></div>
            </dl>
        </div>

        {{-- The club map, as staff see it in the panel. --}}
        <div class="relative">
            <div class="relative rounded-3xl border border-gray-200 bg-white p-5 shadow-2xl">
                <div class="mb-4 flex items-center gap-2"><span class="h-3 w-3 rounded-full bg-[#ff0169]"></span><span class="text-sm font-semibold">{{ __('Карта клуба') }}</span></div>
                <img src="{{ asset('images/club-map.webp') }}" width="810" height="800" alt="{{ __('Карта клуба в панели Zafiro: зоны и ПК с их статусами') }}" class="w-full rounded-2xl border border-gray-100">
            </div>
        </div>
    </section>

    {{-- Features --}}
    <section id="features" class="scroll-mt-20 border-t border-gray-100 py-20">
        <div class="mx-auto max-w-6xl px-4 sm:px-6">
            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-[#e6005e]">{{ __('Возможности') }}</p>
            <h2 class="mt-3 max-w-2xl text-3xl font-bold sm:text-4xl">{{ __('Всё, что нужно клубу каждый день') }}</h2>
            <div class="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                @foreach($features as [$icon, $title, $text])
                    <article class="group rounded-2xl border border-gray-200 bg-gray-50 p-6 transition hover:-translate-y-1 hover:border-[#ff0169]/50 hover:bg-[#ff0169]/[0.06]">
                        <span class="mb-5 flex h-12 w-12 items-center justify-center rounded-xl bg-[#ff0169]/15 text-xl text-[#e6005e] group-hover:bg-[#ff0169] group-hover:text-white"><i class="fas {{ $icon }}" aria-hidden="true"></i></span>
                        <h3 class="text-lg font-semibold">{{ $title }}</h3>
                        <p class="mt-2 text-sm leading-relaxed text-gray-600">{{ $text }}</p>
                    </article>
                @endforeach
            </div>
            <a href="{{ route('product.features') }}" class="mt-8 inline-flex items-center gap-2 font-semibold text-[#d90058] hover:text-gray-900">{{ __('Все возможности') }}<i class="fas fa-arrow-right" aria-hidden="true"></i></a>
        </div>
    </section>

    {{-- Agent --}}
    <section id="agent" class="scroll-mt-20 py-20">
        <div class="mx-auto grid max-w-6xl items-center gap-12 px-4 sm:px-6 lg:grid-cols-2">
            <div class="relative overflow-hidden rounded-3xl border border-gray-200">
                <img src="{{ asset('images/landing/club.jpg') }}" alt="{{ __('Игровое место в компьютерном клубе') }}" class="h-full max-h-[460px] w-full object-cover" loading="lazy">
                <div class="absolute inset-0 bg-gradient-to-t from-black/60 via-transparent to-transparent"></div>
                <div class="absolute bottom-5 left-5 right-5 rounded-2xl border border-white/10 bg-black/50 p-4 text-white backdrop-blur">
                    <p class="text-sm text-white/60">{{ __('Осталось времени') }}</p>
                    <p class="text-3xl font-bold">{{ __(':hours ч :minutes мин', ['hours' => 1, 'minutes' => 27]) }}</p>
                </div>
            </div>
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-[#e6005e]">{{ __('Агент на ПК') }}</p>
                <h2 class="mt-3 text-3xl font-bold sm:text-4xl">{{ __('Клиент сам покупает время, а ПК защищён') }}</h2>
                <ul class="mt-8 space-y-4 text-gray-700">
                    @foreach([
                        __('Вход по телефону, QR-коду или регистрация прямо на ПК'),
                        __('Тарифы, оплата поминутно и магазин клуба с оплатой с баланса'),
                        __('Блокировка Windows до оплаты и выход сотрудника только по PIN-коду'),
                        __('Обновление установщиком в одну кнопку «Обновить»'),
                    ] as $point)
                        <li class="flex gap-3"><i class="fas fa-circle-check mt-1 text-[#ff0169]" aria-hidden="true"></i><span>{{ $point }}</span></li>
                    @endforeach
                </ul>
                <a href="{{ $downloadUrl }}" class="mt-8 inline-flex items-center gap-2 rounded-xl border border-[#ff0169]/60 px-5 py-3 font-semibold text-[#d90058] hover:bg-[#ff0169] hover:text-white" rel="noopener"><i class="fab fa-windows" aria-hidden="true"></i>{{ __('Скачать агент для Windows') }}</a>
                <a href="{{ route('product.agent') }}" class="ml-4 mt-8 inline-flex items-center gap-2 font-semibold text-gray-700 hover:text-gray-900">{{ __('Подробнее об агенте') }}<i class="fas fa-arrow-right" aria-hidden="true"></i></a>
            </div>
        </div>
    </section>

    {{-- How to start --}}
    <section id="start" class="scroll-mt-20 border-t border-gray-100 py-20">
        <div class="mx-auto max-w-6xl px-4 sm:px-6">
            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-[#e6005e]">{{ __('Как начать') }}</p>
            <h2 class="mt-3 text-3xl font-bold sm:text-4xl">{{ __('Три шага до первой сессии') }}</h2>
            <ol class="mt-12 grid gap-5 md:grid-cols-3">
                @foreach($steps as [$title, $text])
                    <li class="relative rounded-2xl border border-gray-200 bg-gray-50 p-6">
                        <span class="mb-4 flex h-10 w-10 items-center justify-center rounded-full bg-gradient-to-br from-[#ff0169] to-[#ff7aa8] font-bold text-white">{{ $loop->iteration }}</span>
                        <h3 class="text-lg font-semibold">{{ $title }}</h3>
                        <p class="mt-2 text-sm text-gray-600">{{ $text }}</p>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- FAQ --}}
    <section id="faq" class="scroll-mt-20 py-20">
        <div class="mx-auto max-w-3xl px-4 sm:px-6">
            <h2 class="text-center text-3xl font-bold sm:text-4xl">{{ __('Частые вопросы') }}</h2>
            <div class="mt-10 space-y-3">
                @foreach($faq as [$question, $answer])
                    <details class="group rounded-2xl border border-gray-200 bg-gray-50 p-5 open:border-[#ff0169]/40">
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 font-semibold">{{ $question }}<i class="fas fa-plus text-[#e6005e] transition group-open:rotate-45" aria-hidden="true"></i></summary>
                        <p class="mt-3 text-sm leading-relaxed text-gray-600">{{ $answer }}</p>
                    </details>
                @endforeach
            </div>
        </div>
    </section>
@endsection
