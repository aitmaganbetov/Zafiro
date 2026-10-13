@extends('layouts.admin')

@section('page-title', 'Настройки')

@section('content')
<div class="p-6 lg:p-8">
    <div class="mb-6 animate-slide">
        <h3 class="text-3xl font-bold text-gray-900 dark:text-white mb-2">Настройки клуба</h3>
        <p class="text-gray-600 dark:text-gray-400">Управляйте настройками клуба в одном месте.</p>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-[240px_minmax(0,1fr)] gap-6">
        <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 p-3 h-fit animate-slide shadow-sm">
            <div class="space-y-2">
                <a href="{{ route('settings.club') }}" class="flex items-center gap-3 w-full text-left px-4 py-3 rounded-xl {{ request()->routeIs('settings.club') ? 'brand-tab' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800' }} font-medium transition">
                    <i class="fas fa-building w-4"></i>Настройка клуба
                </a>
                <a href="{{ route('settings.finances') }}" class="flex items-center gap-3 w-full text-left px-4 py-3 rounded-xl {{ request()->routeIs('settings.finances') ? 'brand-tab' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800' }} font-medium transition">
                    <i class="fas fa-coins w-4"></i>Финансы
                </a>
                <a href="{{ route('settings.guests') }}" class="flex items-center gap-3 w-full text-left px-4 py-3 rounded-xl {{ request()->routeIs('settings.guests') ? 'brand-tab' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800' }} font-medium transition">
                    <i class="fas fa-users w-4"></i>Гости
                </a>
                <a href="{{ route('settings.api') }}" class="flex items-center gap-3 w-full text-left px-4 py-3 rounded-xl {{ request()->routeIs('settings.api') ? 'brand-tab' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800' }} font-medium transition">
                    <i class="fas fa-code w-4"></i>API
                </a>
            </div>
        </div>

        <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 p-6 animate-slide shadow-sm animation-delay-100">
            @if (session('status'))
                <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">
                    {{ session('status') }}
                </div>
            @endif

            <form action="{{ route('settings.save') }}" method="POST" class="space-y-6">
                @csrf
                <input type="hidden" name="section" value="@yield('settings-section', 'club')">
                @yield('settings-content')

                <div class="mt-6 flex justify-end">
                    <button type="submit" class="brand-button rounded-xl px-5 py-2.5 text-sm transition">
                        Сохранить
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
