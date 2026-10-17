<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Zafiro — система управления компьютерным клубом') }}</title>
    <meta name="description" content="{{ __('Касса, карта зала, брони, тарифы, магазин и агент на ПК в одной системе. Подключите свой компьютерный клуб за один вечер.') }}">
    <meta property="og:title" content="{{ __('Zafiro — система управления компьютерным клубом') }}">
    <meta property="og:description" content="{{ __('Касса, карта зала, брони, тарифы, магазин и агент на ПК в одной системе. Подключите свой компьютерный клуб за один вечер.') }}">
    <meta property="og:image" content="{{ asset('icons/icon-512.png') }}">
    <meta property="og:type" content="website">
    @include('partials.fonts')
    @include('partials.pwa')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
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
    $hall = [
        ['VIP', 'free', 'busy', 'busy', 'free', 'busy', 'booked'],
        ['Bootcamp', 'busy', 'busy', 'free', 'busy', 'busy', 'busy', 'free', 'busy'],
        ['PS5', 'busy', 'free'],
    ];
    $hallStates = collect($hall)->flatMap(fn (array $zone): array => array_slice($zone, 1));
    $pcNumber = 0;
@endphp
<body class="min-h-screen bg-[#0b0d10] text-white antialiased" style="font-family: Inter, 'Segoe UI', system-ui, sans-serif">
    <div class="pointer-events-none fixed inset-0 overflow-hidden" aria-hidden="true">
        <div class="absolute -top-40 left-1/2 h-[560px] w-[900px] -translate-x-1/2 rounded-full bg-[#ff0169]/20 blur-[140px]"></div>
        <div class="absolute bottom-0 right-[-200px] h-[420px] w-[520px] rounded-full bg-[#7c3aed]/10 blur-[140px]"></div>
    </div>

    <header class="sticky top-0 z-30 border-b border-white/5 bg-[#0b0d10]/80 backdrop-blur">
        <nav class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3 sm:px-6">
            <a href="{{ route('home') }}" class="flex items-center gap-2 font-bold tracking-wide">
                <img src="{{ asset('images/logo.svg') }}" alt="" class="h-9 w-auto">
                <span class="text-lg">ZAFIRO</span>
            </a>
            <div class="hidden items-center gap-6 text-sm text-white/70 md:flex">
                <a href="#features" class="hover:text-white">{{ __('Возможности') }}</a>
                <a href="#agent" class="hover:text-white">{{ __('Агент') }}</a>
                <a href="#start" class="hover:text-white">{{ __('Как начать') }}</a>
                <a href="#faq" class="hover:text-white">{{ __('Вопросы') }}</a>
                <a href="{{ route('wiki') }}" class="hover:text-white">{{ __('Wiki') }}</a>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('wiki') }}" class="rounded-lg px-3 py-2 text-sm text-white/80 hover:text-white md:hidden">{{ __('Wiki') }}</a>
                @auth
                    <a href="{{ route('dashboard') }}" class="rounded-lg bg-[#ff0169] px-4 py-2 text-sm font-semibold shadow-lg shadow-[#ff0169]/30 hover:bg-[#e6005e]">{{ __('Открыть панель') }}</a>
                @else
                    <a href="{{ route('auth.show-login') }}" class="rounded-lg px-3 py-2 text-sm text-white/80 hover:text-white">{{ __('Войти') }}</a>
                    <a href="{{ route('auth.show-register') }}" class="rounded-lg bg-[#ff0169] px-4 py-2 text-sm font-semibold shadow-lg shadow-[#ff0169]/30 hover:bg-[#e6005e]">{{ __('Попробовать') }}</a>
                @endauth
            </div>
        </nav>
    </header>

    <main class="relative">
        {{-- Hero --}}
        <section class="mx-auto grid max-w-6xl items-center gap-12 px-4 pb-20 pt-14 sm:px-6 lg:grid-cols-2 lg:pt-20">
            <div>
                <p class="mb-5 inline-flex items-center gap-2 rounded-full border border-[#ff0169]/40 bg-[#ff0169]/10 px-3 py-1 text-sm text-[#ff7aa8]"><i class="fas fa-bolt" aria-hidden="true"></i>{{ __('Для компьютерных клубов и PS-клубов') }}</p>
                <h1 class="bg-gradient-to-r from-white via-white to-[#ff7aa8] bg-clip-text text-4xl font-extrabold leading-tight text-transparent sm:text-5xl lg:text-6xl">{{ __('Весь клуб — в одном окне') }}</h1>
                <p class="mt-6 max-w-xl text-lg text-white/70">{{ __('Касса, карта зала, брони, тарифы, магазин и агент на ПК в одной системе. Подключите свой компьютерный клуб за один вечер.') }}</p>
                <div class="mt-8 flex flex-wrap gap-3">
                    @auth
                        <a href="{{ route('dashboard') }}" class="rounded-xl bg-[#ff0169] px-6 py-3 font-semibold shadow-xl shadow-[#ff0169]/30 hover:bg-[#e6005e]">{{ __('Открыть панель') }}</a>
                    @else
                        <a href="{{ route('auth.show-register') }}" class="rounded-xl bg-[#ff0169] px-6 py-3 font-semibold shadow-xl shadow-[#ff0169]/30 hover:bg-[#e6005e]">{{ __(':days дней бесплатно', ['days' => $trialDays]) }}</a>
                    @endauth
                    <a href="#features" class="rounded-xl border border-white/15 px-6 py-3 font-semibold text-white/90 hover:border-white/40">{{ __('Посмотреть возможности') }}</a>
                </div>
                <dl class="mt-10 grid max-w-lg grid-cols-3 gap-4 text-center sm:text-left">
                    <div><dt class="text-sm text-white/50">{{ __('Запуск') }}</dt><dd class="text-xl font-bold">{{ __('1 вечер') }}</dd></div>
                    <div><dt class="text-sm text-white/50">{{ __('Оборудование') }}</dt><dd class="text-xl font-bold">{{ __('не нужно') }}</dd></div>
                    <div><dt class="text-sm text-white/50">{{ __('Языки') }}</dt><dd class="text-xl font-bold">RU · KZ · EN</dd></div>
                </dl>
            </div>

            {{-- A stylised club map, as staff see it in the panel. --}}
            <div class="relative" aria-hidden="true">
                <div class="absolute -inset-4 rounded-[2rem] bg-gradient-to-br from-[#ff0169]/30 to-transparent blur-2xl"></div>
                <div class="relative rounded-3xl border border-white/10 bg-[#11151a]/90 p-5 shadow-2xl">
                    <div class="mb-4 flex items-center justify-between">
                        <div class="flex items-center gap-2"><span class="h-3 w-3 rounded-full bg-[#ff0169]"></span><span class="text-sm font-semibold">{{ __('Карта клуба') }}</span></div>
                        <span class="rounded-full bg-white/5 px-3 py-1 text-xs text-white/60">{{ __('Сейчас занято: :busy из :total', ['busy' => $hallStates->filter(fn ($state) => $state === 'busy')->count(), 'total' => $hallStates->count()]) }}</span>
                    </div>
                    <div class="grid gap-3">
                        @foreach($hall as $zone)
                            <div class="rounded-2xl border border-white/10 bg-white/[0.03] p-3">
                                <p class="mb-2 text-xs font-semibold uppercase tracking-wider text-white/50">{{ $zone[0] }}</p>
                                <div class="flex flex-wrap gap-2">
                                    @foreach(array_slice($zone, 1) as $state)
                                        <span class="flex h-11 w-11 items-center justify-center rounded-xl text-sm font-bold {{ ['free' => 'bg-emerald-500/15 text-emerald-300 ring-1 ring-emerald-400/40', 'busy' => 'bg-[#ff0169]/20 text-[#ff7aa8] ring-1 ring-[#ff0169]/50', 'booked' => 'bg-amber-400/15 text-amber-300 ring-1 ring-amber-300/40'][$state] }}">{{ ++$pcNumber }}</span>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-4 flex flex-wrap gap-4 text-xs text-white/60">
                        <span class="flex items-center gap-1.5"><i class="h-2.5 w-2.5 rounded-full bg-emerald-400"></i>{{ __('Свободен') }}</span>
                        <span class="flex items-center gap-1.5"><i class="h-2.5 w-2.5 rounded-full bg-[#ff0169]"></i>{{ __('Идёт сессия') }}</span>
                        <span class="flex items-center gap-1.5"><i class="h-2.5 w-2.5 rounded-full bg-amber-300"></i>{{ __('Бронь') }}</span>
                    </div>
                </div>
            </div>
        </section>

        {{-- Features --}}
        <section id="features" class="scroll-mt-20 border-t border-white/5 py-20">
            <div class="mx-auto max-w-6xl px-4 sm:px-6">
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-[#ff4d8f]">{{ __('Возможности') }}</p>
                <h2 class="mt-3 max-w-2xl text-3xl font-bold sm:text-4xl">{{ __('Всё, что нужно клубу каждый день') }}</h2>
                <div class="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach($features as [$icon, $title, $text])
                        <article class="group rounded-2xl border border-white/10 bg-white/[0.03] p-6 transition hover:-translate-y-1 hover:border-[#ff0169]/50 hover:bg-[#ff0169]/[0.06]">
                            <span class="mb-5 flex h-12 w-12 items-center justify-center rounded-xl bg-[#ff0169]/15 text-xl text-[#ff4d8f] group-hover:bg-[#ff0169] group-hover:text-white"><i class="fas {{ $icon }}" aria-hidden="true"></i></span>
                            <h3 class="text-lg font-semibold">{{ $title }}</h3>
                            <p class="mt-2 text-sm leading-relaxed text-white/60">{{ $text }}</p>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- Agent --}}
        <section id="agent" class="scroll-mt-20 py-20">
            <div class="mx-auto grid max-w-6xl items-center gap-12 px-4 sm:px-6 lg:grid-cols-2">
                <div class="relative overflow-hidden rounded-3xl border border-white/10">
                    <img src="{{ asset('images/landing/club.jpg') }}" alt="{{ __('Игровое место в компьютерном клубе') }}" class="h-full max-h-[460px] w-full object-cover" loading="lazy">
                    <div class="absolute inset-0 bg-gradient-to-t from-[#0b0d10] via-transparent to-transparent"></div>
                    <div class="absolute bottom-5 left-5 right-5 rounded-2xl border border-white/10 bg-black/50 p-4 backdrop-blur">
                        <p class="text-sm text-white/60">{{ __('Осталось времени') }}</p>
                        <p class="text-3xl font-bold">{{ __(':hours ч :minutes мин', ['hours' => 1, 'minutes' => 27]) }}</p>
                    </div>
                </div>
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.2em] text-[#ff4d8f]">{{ __('Агент на ПК') }}</p>
                    <h2 class="mt-3 text-3xl font-bold sm:text-4xl">{{ __('Клиент сам покупает время, а ПК защищён') }}</h2>
                    <ul class="mt-8 space-y-4 text-white/75">
                        @foreach([
                            __('Вход по телефону, QR-коду или регистрация прямо на ПК'),
                            __('Тарифы, оплата поминутно и магазин клуба с оплатой с баланса'),
                            __('Блокировка Windows до оплаты и выход сотрудника только по PIN-коду'),
                            __('Обновление установщиком в одну кнопку «Обновить»'),
                        ] as $point)
                            <li class="flex gap-3"><i class="fas fa-circle-check mt-1 text-[#ff0169]" aria-hidden="true"></i><span>{{ $point }}</span></li>
                        @endforeach
                    </ul>
                    <a href="{{ $downloadUrl }}" class="mt-8 inline-flex items-center gap-2 rounded-xl border border-[#ff0169]/60 px-5 py-3 font-semibold text-[#ff7aa8] hover:bg-[#ff0169] hover:text-white" rel="noopener"><i class="fab fa-windows" aria-hidden="true"></i>{{ __('Скачать агент для Windows') }}</a>
                </div>
            </div>
        </section>

        {{-- How to start --}}
        <section id="start" class="scroll-mt-20 border-t border-white/5 py-20">
            <div class="mx-auto max-w-6xl px-4 sm:px-6">
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-[#ff4d8f]">{{ __('Как начать') }}</p>
                <h2 class="mt-3 text-3xl font-bold sm:text-4xl">{{ __('Три шага до первой сессии') }}</h2>
                <ol class="mt-12 grid gap-5 md:grid-cols-3">
                    @foreach($steps as [$title, $text])
                        <li class="relative rounded-2xl border border-white/10 bg-white/[0.03] p-6">
                            <span class="mb-4 flex h-10 w-10 items-center justify-center rounded-full bg-gradient-to-br from-[#ff0169] to-[#ff7aa8] font-bold">{{ $loop->iteration }}</span>
                            <h3 class="text-lg font-semibold">{{ $title }}</h3>
                            <p class="mt-2 text-sm text-white/60">{{ $text }}</p>
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
                        <details class="group rounded-2xl border border-white/10 bg-white/[0.03] p-5 open:border-[#ff0169]/40">
                            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 font-semibold">{{ $question }}<i class="fas fa-plus text-[#ff4d8f] transition group-open:rotate-45" aria-hidden="true"></i></summary>
                            <p class="mt-3 text-sm leading-relaxed text-white/65">{{ $answer }}</p>
                        </details>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- Call to action --}}
        <section class="px-4 pb-24 sm:px-6">
            <div class="mx-auto max-w-6xl overflow-hidden rounded-3xl bg-gradient-to-br from-[#ff0169] to-[#b0004a] p-10 text-center shadow-2xl shadow-[#ff0169]/30 sm:p-14">
                <h2 class="text-3xl font-extrabold sm:text-4xl">{{ __('Готовы навести порядок в клубе?') }}</h2>
                <p class="mx-auto mt-4 max-w-xl text-white/85">{{ __('Подключите клуб сегодня — :days дней бесплатно, без карты и договора.', ['days' => $trialDays]) }}</p>
                <a href="{{ auth()->check() ? route('dashboard') : route('auth.show-register') }}" class="mt-8 inline-block rounded-xl bg-white px-7 py-3 font-semibold text-[#d90058] hover:bg-white/90">{{ auth()->check() ? __('Открыть панель') : __('Зарегистрировать клуб') }}</a>
            </div>
        </section>
    </main>

    <footer class="border-t border-white/5 py-8">
        <div class="mx-auto flex max-w-6xl flex-col items-center justify-between gap-4 px-4 text-sm text-white/50 sm:flex-row sm:px-6">
            <div class="flex items-center gap-2"><img src="{{ asset('images/logo.svg') }}" alt="" class="h-6 w-auto"><span>© {{ now()->year }} Zafiro</span></div>
            <div class="flex gap-5">
                <a href="#features" class="hover:text-white">{{ __('Возможности') }}</a>
                <a href="#faq" class="hover:text-white">{{ __('Вопросы') }}</a>
                <a href="{{ route('wiki') }}" class="hover:text-white">{{ __('Wiki') }}</a>
                <a href="{{ route('auth.show-login') }}" class="hover:text-white">{{ __('Войти') }}</a>
            </div>
        </div>
    </footer>
</body>
</html>
