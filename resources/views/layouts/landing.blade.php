<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title')</title>
    <meta name="description" content="@yield('description')">
    <meta property="og:title" content="@yield('title')">
    <meta property="og:description" content="@yield('description')">
    <meta property="og:image" content="{{ asset('icons/icon-512.png') }}">
    <meta property="og:type" content="website">
    @include('partials.fonts')
    @include('partials.pwa')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
@php
    $home = request()->routeIs('home') ? '' : route('home');
    $productPages = [
        ['product.features', 'fa-layer-group', __('Возможности'), __('Касса, зал, тарифы, клиенты, склад и команда')],
        ['product.agent', 'fa-desktop', __('Агент на ПК'), __('Что видит клиент и как защищён ПК')],
    ];
@endphp
<body class="min-h-screen bg-white text-gray-900 antialiased" style="font-family: Inter, 'Segoe UI', system-ui, sans-serif">
    <div class="pointer-events-none fixed inset-0 overflow-hidden" aria-hidden="true">
        <div class="absolute -top-40 left-1/2 h-[560px] w-[900px] -translate-x-1/2 rounded-full bg-[#ff0169]/10 blur-[140px]"></div>
        <div class="absolute bottom-0 right-[-200px] h-[420px] w-[520px] rounded-full bg-[#7c3aed]/10 blur-[140px]"></div>
    </div>

    <header class="sticky top-0 z-30 border-b border-gray-100 bg-white/80 backdrop-blur">
        <nav class="mx-auto flex max-w-6xl items-center justify-between gap-3 px-4 py-3 sm:px-6">
            <a href="{{ route('home') }}" class="flex shrink-0 items-center gap-2 font-bold tracking-wide">
                <img src="{{ asset('images/logo-dark.svg') }}" alt="" class="h-9 w-auto">
                <span class="hidden text-lg sm:inline">ZAFIRO</span>
            </a>
            <div class="flex items-center gap-6 text-sm text-gray-600">
                <details class="group relative" data-landing-menu>
                    <summary class="flex cursor-pointer list-none items-center gap-1.5 rounded-lg py-2 hover:text-gray-900 group-open:text-gray-900 {{ request()->routeIs('product.*') ? 'text-gray-900' : '' }}">{{ __('О продукте') }}<i class="fas fa-chevron-down text-xs transition group-open:rotate-180" aria-hidden="true"></i></summary>
                    <div class="absolute left-1/2 top-full z-40 mt-2 w-72 -translate-x-1/2 rounded-2xl border border-gray-200 bg-white p-2 shadow-2xl shadow-gray-900/10">
                        @foreach($productPages as [$name, $icon, $title, $text])
                            <a href="{{ route($name) }}" class="flex gap-3 rounded-xl p-3 hover:bg-gray-100" @if(request()->routeIs($name)) aria-current="page" @endif>
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-[#ff0169]/15 text-[#e6005e]"><i class="fas {{ $icon }}" aria-hidden="true"></i></span>
                                <span><span class="block font-semibold text-gray-900">{{ $title }}</span><span class="block text-xs text-gray-500">{{ $text }}</span></span>
                            </a>
                        @endforeach
                        <div class="mt-1 border-t border-gray-200 pt-1 md:hidden">
                            <a href="{{ $home }}#start" class="block rounded-xl px-3 py-2 hover:bg-gray-100">{{ __('Как начать') }}</a>
                            <a href="{{ $home }}#faq" class="block rounded-xl px-3 py-2 hover:bg-gray-100">{{ __('Вопросы') }}</a>
                            <a href="{{ route('wiki') }}" class="block rounded-xl px-3 py-2 hover:bg-gray-100">{{ __('Wiki') }}</a>
                            @guest<a href="{{ route('auth.show-login') }}" class="block rounded-xl px-3 py-2 hover:bg-gray-100 sm:hidden">{{ __('Войти') }}</a>@endguest
                        </div>
                    </div>
                </details>
                <a href="{{ $home }}#start" class="hidden hover:text-gray-900 md:inline">{{ __('Как начать') }}</a>
                <a href="{{ $home }}#faq" class="hidden hover:text-gray-900 md:inline">{{ __('Вопросы') }}</a>
                <a href="{{ route('wiki') }}" class="hidden hover:text-gray-900 md:inline">{{ __('Wiki') }}</a>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                @auth
                    <a href="{{ route('dashboard') }}" class="rounded-lg bg-[#ff0169] px-4 py-2 text-sm font-semibold shadow-lg shadow-[#ff0169]/30 hover:bg-[#e6005e] text-white">{{ __('Открыть панель') }}</a>
                @else
                    <a href="{{ route('auth.show-login') }}" class="hidden rounded-lg px-3 py-2 text-sm text-gray-700 hover:text-gray-900 sm:inline">{{ __('Войти') }}</a>
                    <a href="{{ route('auth.show-register') }}" class="rounded-lg bg-[#ff0169] px-4 py-2 text-sm font-semibold shadow-lg shadow-[#ff0169]/30 hover:bg-[#e6005e] text-white">{{ __('Попробовать') }}</a>
                @endauth
            </div>
        </nav>
    </header>

    <main class="relative">
        @yield('content')

        {{-- Call to action --}}
        <section class="px-4 pb-24 sm:px-6">
            <div class="mx-auto max-w-6xl overflow-hidden rounded-3xl bg-gradient-to-br from-[#ff0169] to-[#b0004a] p-10 text-center text-white shadow-2xl shadow-[#ff0169]/30 sm:p-14">
                <h2 class="text-3xl font-extrabold sm:text-4xl">{{ __('Готовы навести порядок в клубе?') }}</h2>
                <p class="mx-auto mt-4 max-w-xl text-white/85">{{ __('Подключите клуб сегодня — :days дней бесплатно, без карты и договора.', ['days' => $trialDays]) }}</p>
                <a href="{{ auth()->check() ? route('dashboard') : route('auth.show-register') }}" class="mt-8 inline-block rounded-xl bg-white px-7 py-3 font-semibold text-[#d90058] hover:bg-white/90">{{ auth()->check() ? __('Открыть панель') : __('Зарегистрировать клуб') }}</a>
            </div>
        </section>
    </main>

    <footer class="border-t border-gray-100 py-8">
        <div class="mx-auto flex max-w-6xl flex-col items-center justify-between gap-4 px-4 text-sm text-gray-500 sm:flex-row sm:px-6">
            <div class="flex items-center gap-2"><img src="{{ asset('images/logo-dark.svg') }}" alt="" class="h-6 w-auto"><span>© {{ now()->year }} Zafiro</span></div>
            <div class="flex flex-wrap justify-center gap-5">
                <a href="{{ route('product.features') }}" class="hover:text-gray-900">{{ __('Возможности') }}</a>
                <a href="{{ route('product.agent') }}" class="hover:text-gray-900">{{ __('Агент на ПК') }}</a>
                <a href="{{ $home }}#faq" class="hover:text-gray-900">{{ __('Вопросы') }}</a>
                <a href="{{ route('wiki') }}" class="hover:text-gray-900">{{ __('Wiki') }}</a>
                <a href="{{ route('auth.show-login') }}" class="hover:text-gray-900">{{ __('Войти') }}</a>
            </div>
        </div>
    </footer>

    <script>
        // The "About the product" menu closes on a click elsewhere and on Escape.
        (() => {
            const menu = document.querySelector('[data-landing-menu]');
            document.addEventListener('click', event => { if (!menu.contains(event.target)) menu.open = false; });
            document.addEventListener('keydown', event => { if (event.key === 'Escape') menu.open = false; });
        })();
    </script>
</body>
</html>
