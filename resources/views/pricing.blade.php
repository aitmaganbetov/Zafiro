@extends('layouts.landing')

@section('title', __('Тарифы Zafiro — подписка для компьютерного клуба'))
@section('description', __('Тарифы Zafiro для компьютерных и PS-клубов: пробный период без карты и договора, все возможности в любом тарифе.'))

@section('content')
@php
    $included = [
        ['fa-window-maximize', __('Панель клуба в браузере')],
        ['fa-desktop', __('Агент на ПК и его обновления')],
        ['fa-mobile-screen', __('Приложения для гостей и сотрудников')],
        ['fa-cash-register', __('Касса, смены и X/Z-отчёты')],
        ['fa-map-location-dot', __('Карта клуба, тарифы и брони')],
        ['fa-boxes-stacked', __('Склад, магазин в агенте и услуги')],
        ['fa-user-shield', __('Сотрудники, права и журнал действий')],
        ['fa-plug', __('API и вебхуки')],
        ['fa-language', __('Русский, казахский и английский')],
    ];
    $questions = [
        [__('Нужна ли карта для пробного периода?'), __('Нет. Зарегистрируйте клуб — пробный период начнётся сразу, без карты и договора.')],
        [__('Что будет, когда подписка закончится?'), __('Панель, агенты на ПК и API клуба перестанут работать, пока подписку не продлят. Данные клуба при этом сохраняются.')],
        [__('Можно ли сменить тариф?'), __('Да. Тариф меняется без переноса данных: клуб, ПК, клиенты и история остаются на месте.')],
        [__('Есть ли скидки?'), __('Для сетей клубов и по договорённости Zafiro даёт клубу индивидуальную скидку на тариф.')],
    ];
@endphp
<section class="mx-auto max-w-6xl px-4 pb-12 pt-14 text-center sm:px-6 lg:pt-20">
    <p class="text-sm font-semibold uppercase tracking-[0.2em] text-[#e6005e]">{{ __('Тарифы') }}</p>
    <h1 class="mx-auto mt-3 max-w-3xl text-4xl font-extrabold leading-tight text-gray-900 sm:text-5xl">{{ __('Простая подписка для клуба') }}</h1>
    <p class="mx-auto mt-6 max-w-2xl text-lg text-gray-600">{{ __('Начните с :days дней бесплатно, без карты и договора. Все возможности Zafiro — в любом тарифе.', ['days' => $trialDays]) }}</p>
</section>

<section class="pb-16">
    <div class="mx-auto flex max-w-6xl flex-wrap justify-center gap-5 px-4 sm:px-6">
        @forelse($plans as $plan)
            @php($free = $plan->price_monthly === null && $plan->trial_days > 0)
            <article class="flex w-full flex-col rounded-3xl border border-gray-200 bg-white p-7 shadow-sm sm:w-80">
                <h2 class="text-lg font-semibold">{{ $plan->name }}</h2>
                <p class="mt-4">
                    @if($plan->price_monthly !== null)
                        <span class="block text-4xl font-extrabold">{{ number_format((float) $plan->price_monthly, 0, '.', ' ') }} ₸</span>
                        <span class="mt-1 block text-gray-500">{{ __('за ПК в месяц') }}</span>
                    @elseif($free)
                        <span class="text-4xl font-extrabold">{{ __('Бесплатно') }}</span>
                    @else
                        <span class="text-4xl font-extrabold">{{ __('По запросу') }}</span>
                    @endif
                </p>
                @if($plan->trial_days > 0)
                    <p class="mt-2 text-sm text-[#e6005e]">{{ $free ? trans_choice(':count день|:count дня|:count дней', $plan->trial_days) : trans_choice(':count день бесплатно|:count дня бесплатно|:count дней бесплатно', $plan->trial_days) }}</p>
                @endif
                <ul class="mt-6 flex-1 space-y-3 text-sm text-gray-700">
                    @forelse($plan->features ?? [] as $feature)
                        <li class="flex gap-2"><i class="fas fa-check mt-1 text-xs text-[#e6005e]" aria-hidden="true"></i><span>{{ $feature }}</span></li>
                    @empty
                        <li class="flex gap-2"><i class="fas fa-check mt-1 text-xs text-[#e6005e]" aria-hidden="true"></i><span>{{ __('Все возможности Zafiro') }}</span></li>
                    @endforelse
                </ul>
                <a href="{{ auth()->check() ? route('dashboard') : route('auth.show-register') }}" class="mt-8 rounded-xl bg-[#ff0169] px-5 py-3 text-center font-semibold text-white shadow-lg shadow-[#ff0169]/30 hover:bg-[#e6005e]">{{ auth()->check() ? __('Открыть панель') : __('Зарегистрировать клуб') }}</a>
            </article>
        @empty
            <p class="text-gray-600">{{ __('Тарифы скоро появятся.') }}</p>
        @endforelse
    </div>
</section>

<section class="border-t border-gray-100 py-16">
    <div class="mx-auto max-w-6xl px-4 sm:px-6">
        <p class="text-sm font-semibold uppercase tracking-[0.2em] text-[#e6005e]">{{ __('В любом тарифе') }}</p>
        <h2 class="mt-3 max-w-2xl text-3xl font-bold">{{ __('Без урезанных версий') }}</h2>
        <ul class="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach($included as [$icon, $text])
                <li class="flex items-center gap-4 rounded-2xl border border-gray-200 bg-gray-50 p-4">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[#ff0169]/15 text-[#e6005e]"><i class="fas {{ $icon }}" aria-hidden="true"></i></span>
                    <span class="font-medium">{{ $text }}</span>
                </li>
            @endforeach
        </ul>
        <a href="{{ route('product.features') }}" class="mt-8 inline-flex items-center gap-2 font-semibold text-[#d90058] hover:text-gray-900">{{ __('Все возможности') }}<i class="fas fa-arrow-right" aria-hidden="true"></i></a>
    </div>
</section>

<section class="border-t border-gray-100 py-16">
    <div class="mx-auto max-w-3xl px-4 sm:px-6">
        <h2 class="text-center text-3xl font-bold">{{ __('Вопросы об оплате') }}</h2>
        <div class="mt-10 space-y-3">
            @foreach($questions as [$question, $answer])
                <details class="group rounded-2xl border border-gray-200 bg-gray-50 p-5 open:border-[#ff0169]/40">
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-4 font-semibold">{{ $question }}<i class="fas fa-plus text-[#e6005e] transition group-open:rotate-45" aria-hidden="true"></i></summary>
                    <p class="mt-3 text-sm leading-relaxed text-gray-600">{{ $answer }}</p>
                </details>
            @endforeach
        </div>
    </div>
</section>
@endsection
