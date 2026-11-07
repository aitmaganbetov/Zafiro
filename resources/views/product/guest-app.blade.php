@extends('layouts.landing')

@section('title', __('Приложение Zafiro для гостей — баланс, вход на ПК по QR-коду, друзья'))
@section('description', __('Мобильное приложение Zafiro для гостей клуба: баланс и бонусы, вход на ПК по QR-коду, новости клуба, друзья и переводы, история операций.'))

@section('content')
@php
    $features = [
        ['fa-wallet', __('Баланс и скидка'), [__('Основной и бонусный баланс в клубе'), __('Персональная или накопительная скидка'), __('Обновление одной кнопкой')]],
        ['fa-qrcode', __('Вход на ПК по QR-коду'), [__('Наведите камеру на QR-код на экране входа ПК'), __('Пароль на ПК вводить не нужно'), __('Фонарик для тёмного зала')]],
        ['fa-newspaper', __('Новости'), [__('Новости и акции клуба'), __('Новости Zafiro для всех клубов'), __('Те же новости, что на главной в агенте')]],
        ['fa-user-group', __('Друзья и переводы'), [__('Добавить друга по номеру телефона'), __('Заявки в друзья: принять или отклонить'), __('Перевод баланса с подтверждением кодом в Telegram')]],
        ['fa-clock-rotate-left', __('История'), [__('Пополнения, покупки, возвраты и переводы'), __('Все операции в одной ленте'), __('Подгрузка более старых операций')]],
        ['fa-building', __('Несколько клубов'), [__('Один аккаунт по номеру телефона во всех клубах Zafiro'), __('Выбор клуба при входе'), __('Сменить клуб в любой момент')]],
    ];
    $forClub = [
        ['fa-bolt', __('Быстрый вход'), __('Гость садится за ПК и входит за пару секунд — без ввода номера и пароля на клавиатуре клуба.')],
        ['fa-bullhorn', __('Новости в кармане'), __('Акции и турниры видны гостю не только в клубе, но и дома.')],
        ['fa-handshake', __('Друзья без кассы'), __('Гости сами переводят друг другу баланс, если клуб это разрешил.')],
    ];
@endphp
<section class="mx-auto grid max-w-6xl items-center gap-12 px-4 pb-16 pt-14 sm:px-6 lg:grid-cols-[1.2fr_1fr] lg:pt-20">
    <div>
        <p class="text-sm font-semibold uppercase tracking-[0.2em] text-[#e6005e]">{{ __('Продукты') }}</p>
        <h1 class="mt-3 bg-gradient-to-r from-gray-900 via-gray-900 to-[#ff0169] bg-clip-text text-4xl font-extrabold leading-tight text-transparent sm:text-5xl">{{ __('Приложение для гостей') }}</h1>
        <p class="mt-6 max-w-xl text-lg text-gray-600">{{ __('Клуб в телефоне гостя: баланс и бонусы, вход на ПК по QR-коду, новости клуба и переводы друзьям.') }}</p>
        <dl class="mt-10 grid max-w-lg grid-cols-3 gap-4 text-center sm:text-left">
            <div><dt class="text-sm text-gray-500">{{ __('Платформа') }}</dt><dd class="text-xl font-bold">Android</dd></div>
            <div><dt class="text-sm text-gray-500">{{ __('Вход') }}</dt><dd class="text-xl font-bold">{{ __('как на ПК') }}</dd></div>
            <div><dt class="text-sm text-gray-500">{{ __('Клубы') }}</dt><dd class="text-xl font-bold">{{ __('все сразу') }}</dd></div>
        </dl>
        <p class="mt-8 max-w-xl rounded-2xl border border-gray-200 bg-gray-50 p-4 text-sm text-gray-600"><i class="fab fa-android mr-2 text-[#e6005e]" aria-hidden="true"></i>{{ __('Гость входит тем же номером и паролем, что и на ПК клуба. Как установить приложение — уточните в поддержке Zafiro.') }}</p>
    </div>

    {{-- A stylised phone with the balance screen. --}}
    <div class="relative mx-auto w-full max-w-[300px]" aria-hidden="true">
        <div class="absolute -inset-6 rounded-[3rem] bg-gradient-to-br from-[#ff0169]/20 to-transparent blur-2xl"></div>
        <div class="relative rounded-[2.5rem] border-[10px] border-gray-900 bg-white p-4 shadow-2xl">
            <div class="mx-auto mb-4 h-1.5 w-16 rounded-full bg-gray-200"></div>
            <p class="text-xs text-gray-500">{{ __('Клуб') }}</p>
            <p class="font-bold">Cyber Arena</p>
            <div class="mt-4 rounded-2xl bg-gradient-to-br from-[#ff0169] to-[#b0004a] p-4 text-white">
                <p class="text-xs text-white/80">{{ __('Основной баланс') }}</p>
                <p class="text-3xl font-extrabold">4 500 ₸</p>
                <div class="mt-3 flex justify-between text-xs"><span>{{ __('Бонусы') }}: 350</span><span>{{ __('Скидка') }}: 5%</span></div>
            </div>
            <div class="mt-4 flex items-center gap-3 rounded-2xl border border-gray-200 p-3">
                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-[#ff0169]/15 text-[#e6005e]"><i class="fas fa-qrcode"></i></span>
                <span class="text-sm font-semibold">{{ __('Войти на ПК по QR-коду') }}</span>
            </div>
            <div class="mt-4 grid grid-cols-5 gap-1 border-t border-gray-100 pt-3 text-center text-[10px] text-gray-400">
                <span class="flex flex-col items-center gap-1 text-[#e6005e]"><i class="fas fa-wallet text-base"></i>{{ __('Баланс') }}</span>
                <span class="flex flex-col items-center gap-1"><i class="fas fa-newspaper text-base"></i>{{ __('Новости') }}</span>
                <span class="flex flex-col items-center gap-1"><i class="fas fa-user-group text-base"></i>{{ __('Друзья') }}</span>
                <span class="flex flex-col items-center gap-1"><i class="fas fa-clock-rotate-left text-base"></i>{{ __('История') }}</span>
                <span class="flex flex-col items-center gap-1"><i class="fas fa-user text-base"></i>{{ __('Профиль') }}</span>
            </div>
        </div>
    </div>
</section>

<section class="border-t border-gray-100 py-16">
    <div class="mx-auto max-w-6xl px-4 sm:px-6">
        <p class="text-sm font-semibold uppercase tracking-[0.2em] text-[#e6005e]">{{ __('Возможности') }}</p>
        <h2 class="mt-3 max-w-2xl text-3xl font-bold">{{ __('Всё, что гостю нужно от клуба, — в телефоне') }}</h2>
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
        <p class="text-sm font-semibold uppercase tracking-[0.2em] text-[#e6005e]">{{ __('Для клуба') }}</p>
        <h2 class="mt-3 max-w-2xl text-3xl font-bold">{{ __('Что это даёт клубу') }}</h2>
        <div class="mt-10 grid gap-5 md:grid-cols-3">
            @foreach($forClub as [$icon, $title, $text])
                <article class="flex gap-5 rounded-2xl border border-gray-200 bg-gray-50 p-6">
                    <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-[#ff0169]/15 text-xl text-[#e6005e]"><i class="fas {{ $icon }}" aria-hidden="true"></i></span>
                    <div><h3 class="text-lg font-semibold">{{ $title }}</h3><p class="mt-2 text-sm leading-relaxed text-gray-600">{{ $text }}</p></div>
                </article>
            @endforeach
        </div>
        <p class="mt-8 text-sm text-gray-500">{{ __('Вход по QR-коду, друзья и переводы включаются в настройках клуба.') }}</p>
    </div>
</section>
@endsection
