<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>@yield('page-title', __('Кабинет суперадмина')) · {{ config('app.name') }}</title>
    @include('partials.fonts')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body class="admin-body dark:bg-gray-950">
    <div class="overlay" id="overlay"></div>
    <div class="admin-app">
        <script>try{if(localStorage.getItem('zafiro.sidebarCollapsed')==='1'&&!matchMedia('(max-width: 768px)').matches)document.currentScript.parentElement.classList.add('sidebar-collapsed')}catch(e){}</script>
        @include('partials.superadmin-sidebar')
        <main class="admin-main">
            <header class="admin-topbar">
                <div class="admin-topbar-inner">
                    <div class="admin-title-wrap"><button type="button" id="sidebarToggle" class="admin-mobile-toggle" aria-label="{{ __('Показать меню') }}" aria-controls="adminSidebar" aria-expanded="false"><i class="fas fa-bars"></i></button><h2 class="admin-title">@yield('page-title', __('Кабинет суперадмина'))</h2></div>
                    <div class="admin-topbar-actions"><span class="rounded-full bg-violet-100 px-3 py-1 text-xs font-semibold text-violet-800 dark:bg-violet-900/40 dark:text-violet-200"><i class="fas fa-shield-halved mr-1" aria-hidden="true"></i>{{ __('Суперадмин') }}</span></div>
                </div>
            </header>
            <div class="admin-content">
                <div class="p-6 lg:p-8">
                    @if(session('status'))<div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700" role="status">{{ session('status') }}</div>@endif
                    @if($errors->any())<div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">{{ $errors->first() }}</div>@endif
                    @yield('content')
                </div>
            </div>
        </main>
    </div>
    @include('partials.sidebar-script')
    @stack('scripts')
</body>
</html>
