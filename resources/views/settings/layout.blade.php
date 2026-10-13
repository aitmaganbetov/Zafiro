@extends('layouts.admin')

@section('page-title', __('Настройки'))

@section('content')
<div class="p-6 lg:p-8">
    <div class="mb-6 animate-slide">
        <h3 class="text-3xl font-bold text-gray-900 dark:text-white mb-2">{{ __('Настройки клуба') }}</h3>
        <p class="text-gray-600 dark:text-gray-400">{{ __('Управляйте настройками клуба в одном месте.') }}</p>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-[240px_minmax(0,1fr)] gap-6">
        <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 p-3 h-fit animate-slide shadow-sm">
            <div class="space-y-2">
                @if (in_array($currentMembership?->role, ['owner', 'admin', 'manager'], true))
                <a href="{{ route('settings.club') }}" class="flex items-center gap-3 w-full text-left px-4 py-3 rounded-xl {{ request()->routeIs('settings.club') ? 'brand-tab' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800' }} font-medium transition">
                    <i class="fas fa-building w-4"></i>{{ __('Настройка клуба') }}
                </a>
                <a href="{{ route('settings.languages') }}" class="flex items-center gap-3 w-full text-left px-4 py-3 rounded-xl {{ request()->routeIs('settings.languages') ? 'brand-tab' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800' }} font-medium transition">
                    <i class="fas fa-language w-4"></i>{{ __('Языки') }}
                </a>
                <a href="{{ route('settings.finances') }}" class="flex items-center gap-3 w-full text-left px-4 py-3 rounded-xl {{ request()->routeIs('settings.finances') ? 'brand-tab' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800' }} font-medium transition">
                    <i class="fas fa-coins w-4"></i>{{ __('Финансы') }}
                </a>
                <a href="{{ route('settings.guests') }}" class="flex items-center gap-3 w-full text-left px-4 py-3 rounded-xl {{ request()->routeIs('settings.guests') ? 'brand-tab' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800' }} font-medium transition">
                    <i class="fas fa-users w-4"></i>{{ __('Гости') }}
                </a>
                <a href="{{ route('settings.api') }}" class="flex items-center gap-3 w-full text-left px-4 py-3 rounded-xl {{ request()->routeIs('settings.api') ? 'brand-tab' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800' }} font-medium transition">
                    <i class="fas fa-code w-4"></i>API
                </a>
                @endif
                @can('manage-integrations')
                <a href="{{ route('settings.integrations') }}" class="flex items-center gap-3 w-full text-left px-4 py-3 rounded-xl {{ request()->routeIs('settings.integrations*') ? 'brand-tab' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800' }} font-medium transition">
                    <i class="fas fa-plug w-4"></i>{{ __('API и интеграции') }}
                </a>
                @endcan
            </div>
        </div>

        <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 p-6 animate-slide shadow-sm animation-delay-100">
            @if (session('status'))
                <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">
                    {{ session('status') }}
                </div>
            @endif

            @hasSection('settings-content')
            <form action="{{ route('settings.save') }}" method="POST" class="space-y-6">
                @csrf
                <input type="hidden" name="section" value="@yield('settings-section', 'club')">
                @yield('settings-content')

                <div class="mt-6 flex justify-end">
                    <button type="submit" class="brand-button rounded-xl px-5 py-2 text-sm transition">
                        {{ __('Сохранить') }}
                    </button>
                </div>
            </form>
            @endif

            @yield('settings-after')
        </div>
    </div>
</div>
@endsection
