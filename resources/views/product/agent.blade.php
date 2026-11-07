@extends('layouts.landing')

@section('title', __('Агент Zafiro на ПК — вход, тарифы, магазин и защита ПК'))
@section('description', __('Агент Zafiro для Windows: клиент сам входит, покупает время и товары, ПК закрыт до оплаты, а сотрудник выходит только по PIN-коду.'))

@section('content')
@php
    $client = [
        ['fa-right-to-bracket', __('Вход'), [__('Телефон и пароль или QR-код с телефона'), __('Регистрация прямо на ПК за минуту'), __('«Забыли пароль?» — код приходит в Telegram')]],
        ['fa-house', __('Главная'), [__('Остаток времени, основной и бонусный баланс'), __('Новости клуба'), __('Язык агента: русский, казахский или английский')]],
        ['fa-stopwatch', __('Тарифы'), [__('Тарифы зоны этого ПК'), __('Любое число минут по цене часа и «на весь баланс»'), __('Абонементы: запуск сохранённого времени без оплаты')]],
        ['fa-bag-shopping', __('Магазин'), [__('Товары клуба по категориям с фото'), __('Корзина и оплата с баланса'), __('Заказ приходит сотрудникам с номером ПК')]],
        ['fa-bell', __('Во время игры'), [__('Уведомления за 10, 5, 3 и 1 минуту — со звуком, поверх игры'), __('Продлить сессию или пересесть на свободный ПК'), __('Ушёл раньше — неиспользованные минуты вернутся на баланс')]],
        ['fa-user', __('Профиль и история'), [__('Покупки, пополнения, возвраты и входы с фильтрами'), __('Друзья и перевод баланса, если клуб их включил'), __('Чат с сотрудниками клуба')]],
    ];
    $protection = [
        ['fa-lock', __('ПК закрыт до оплаты'), __('Без купленного времени клиент видит только экран входа. Время закончилось — агент снова закрывает ПК, а клуб может перезагрузить или выключить его.')],
        ['fa-shield-halved', __('Служба Windows'), __('Служба запускает агент и поднимает его снова, если его закрыли или он завис. Агентом она признаёт только программу из Program Files.')],
        ['fa-user-lock', __('Защищённая учётная запись'), __('В учётной записи клиента нет диспетчера задач, редактора реестра, командной строки, PowerShell, «Выполнить» и панели управления.')],
        ['fa-key', __('Сотрудник — по PIN-коду'), __('Ctrl+Alt+P и PIN-код клуба, который проверяет сервер, или техрежим кнопкой на карте. Такой ПК отмечен на карте красным.')],
    ];
    $install = [
        [__('Скачайте установщик'), __('Один файл ZafiroAgentSetup.exe для Windows 10 и 11.')],
        [__('Запустите от администратора'), __('Отметьте защиту учётной записи клиента и значок на рабочем столе.')],
        [__('Подключите ПК'), __('Введите ключ установки клуба, телефон и пароль сотрудника.')],
        [__('Готово'), __('ПК появляется на карте клуба, агент показывает экран входа.')],
    ];
@endphp
<section class="mx-auto grid max-w-6xl items-center gap-12 px-4 pb-16 pt-14 sm:px-6 lg:grid-cols-2 lg:pt-20">
    <div>
        <p class="text-sm font-semibold uppercase tracking-[0.2em] text-[#e6005e]">{{ __('О продукте') }}</p>
        <h1 class="mt-3 bg-gradient-to-r from-gray-900 via-gray-900 to-[#ff0169] bg-clip-text text-4xl font-extrabold leading-tight text-transparent sm:text-5xl">{{ __('Агент Zafiro на ПК') }}</h1>
        <p class="mt-6 max-w-xl text-lg text-gray-600">{{ __('Программа для Windows на каждом ПК клуба. Клиент сам входит, покупает время и заказывает товары, а ПК закрыт, пока время не оплачено.') }}</p>
        <div class="mt-8 flex flex-wrap gap-3">
            <a href="{{ $downloadUrl }}" class="inline-flex items-center gap-2 rounded-xl bg-[#ff0169] px-6 py-3 font-semibold shadow-xl shadow-[#ff0169]/30 hover:bg-[#e6005e] text-white" rel="noopener"><i class="fab fa-windows" aria-hidden="true"></i>{{ __('Скачать агент для Windows') }}</a>
            <a href="#install" class="rounded-xl border border-gray-300 px-6 py-3 font-semibold text-gray-800 hover:border-gray-400">{{ __('Как установить') }}</a>
        </div>
        <dl class="mt-10 grid max-w-lg grid-cols-3 gap-4 text-center sm:text-left">
            <div><dt class="text-sm text-gray-500">{{ __('Система') }}</dt><dd class="text-xl font-bold">Windows 10/11</dd></div>
            <div><dt class="text-sm text-gray-500">{{ __('Обновление') }}</dt><dd class="text-xl font-bold">{{ __('само') }}</dd></div>
            <div><dt class="text-sm text-gray-500">{{ __('Языки') }}</dt><dd class="text-xl font-bold">RU · KZ · EN</dd></div>
        </dl>
    </div>
    <div class="relative overflow-hidden rounded-3xl border border-gray-200">
        <img src="{{ asset('images/landing/club.jpg') }}" alt="{{ __('Игровое место в компьютерном клубе') }}" class="h-full max-h-[460px] w-full object-cover">
        <div class="absolute inset-0 bg-gradient-to-t from-black/60 via-transparent to-transparent"></div>
        <div class="absolute bottom-5 left-5 right-5 rounded-2xl border border-white/10 bg-black/50 p-4 text-white backdrop-blur">
            <p class="text-sm text-white/60">{{ __('Осталось времени') }}</p>
            <p class="text-3xl font-bold">{{ __(':hours ч :minutes мин', ['hours' => 1, 'minutes' => 27]) }}</p>
        </div>
    </div>
</section>

<section class="border-t border-gray-100 py-16">
    <div class="mx-auto max-w-6xl px-4 sm:px-6">
        <p class="text-sm font-semibold uppercase tracking-[0.2em] text-[#e6005e]">{{ __('Для клиента') }}</p>
        <h2 class="mt-3 max-w-2xl text-3xl font-bold">{{ __('Клиенту не нужно идти на кассу') }}</h2>
        <div class="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            @foreach($client as [$icon, $title, $points])
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
        <p class="text-sm font-semibold uppercase tracking-[0.2em] text-[#e6005e]">{{ __('Защита ПК') }}</p>
        <h2 class="mt-3 max-w-2xl text-3xl font-bold">{{ __('Обойти оплату не получится') }}</h2>
        <div class="mt-10 grid gap-5 md:grid-cols-2">
            @foreach($protection as [$icon, $title, $text])
                <article class="flex gap-5 rounded-2xl border border-gray-200 bg-gray-50 p-6">
                    <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-[#ff0169]/15 text-xl text-[#e6005e]"><i class="fas {{ $icon }}" aria-hidden="true"></i></span>
                    <div><h3 class="text-lg font-semibold">{{ $title }}</h3><p class="mt-2 text-sm leading-relaxed text-gray-600">{{ $text }}</p></div>
                </article>
            @endforeach
        </div>
    </div>
</section>

<section class="border-t border-gray-100 py-16">
    <div class="mx-auto max-w-6xl px-4 sm:px-6">
        <p class="text-sm font-semibold uppercase tracking-[0.2em] text-[#e6005e]">{{ __('Из панели клуба') }}</p>
        <h2 class="mt-3 max-w-2xl text-3xl font-bold">{{ __('Весь зал под рукой') }}</h2>
        <ul class="mt-8 grid gap-4 text-gray-700 sm:grid-cols-2">
            @foreach([
                __('Карта клуба: кто на каком ПК и сколько осталось'),
                __('Включение по сети, перезагрузка и выключение нескольких ПК сразу'),
                __('Сообщение на экраны выбранных ПК поверх игры'),
                __('Время гостю без аккаунта с карты клуба'),
                __('Ответы в чат из раздела «Помощь» агента'),
                __('Список ПК с версией агента, железом и IP'),
            ] as $point)
                <li class="flex gap-3"><i class="fas fa-circle-check mt-1 text-[#ff0169]" aria-hidden="true"></i><span>{{ $point }}</span></li>
            @endforeach
        </ul>
    </div>
</section>

<section id="install" class="scroll-mt-20 border-t border-gray-100 py-16">
    <div class="mx-auto max-w-6xl px-4 sm:px-6">
        <p class="text-sm font-semibold uppercase tracking-[0.2em] text-[#e6005e]">{{ __('Установка') }}</p>
        <h2 class="mt-3 text-3xl font-bold">{{ __('ПК на карте за пару минут') }}</h2>
        <ol class="mt-10 grid gap-5 md:grid-cols-2 lg:grid-cols-4">
            @foreach($install as [$title, $text])
                <li class="rounded-2xl border border-gray-200 bg-gray-50 p-6">
                    <span class="mb-4 flex h-10 w-10 items-center justify-center rounded-full bg-gradient-to-br from-[#ff0169] to-[#ff7aa8] font-bold text-white">{{ $loop->iteration }}</span>
                    <h3 class="text-lg font-semibold">{{ $title }}</h3>
                    <p class="mt-2 text-sm text-gray-600">{{ $text }}</p>
                </li>
            @endforeach
        </ol>
        <div class="mt-8 grid gap-5 md:grid-cols-2">
            <div class="rounded-2xl border border-gray-200 bg-gray-50 p-6">
                <h3 class="flex items-center gap-2 text-lg font-semibold"><i class="fas fa-rotate text-[#e6005e]" aria-hidden="true"></i>{{ __('Обновление') }}</h3>
                <p class="mt-2 text-sm leading-relaxed text-gray-600">{{ __('С автообновлением новая версия ставится сама, когда ПК свободен. Вручную — новый установщик предложит «Обновить», настройки и подключение ПК сохранятся.') }}</p>
            </div>
            <div class="rounded-2xl border border-gray-200 bg-gray-50 p-6">
                <h3 class="flex items-center gap-2 text-lg font-semibold"><i class="fas fa-hard-drive text-[#e6005e]" aria-hidden="true"></i>{{ __('Бездисковые клубы') }}</h3>
                <p class="mt-2 text-sm leading-relaxed text-gray-600">{{ __('Агент ставится один раз в образ суперклиента. После загрузки каждый ПК сам подключается к клубу по его ключу.') }}</p>
            </div>
        </div>
        <a href="{{ route('wiki') }}" class="mt-8 inline-flex items-center gap-2 font-semibold text-[#d90058] hover:text-gray-900">{{ __('Подробная инструкция в Wiki') }}<i class="fas fa-arrow-right" aria-hidden="true"></i></a>
    </div>
</section>
@endsection
