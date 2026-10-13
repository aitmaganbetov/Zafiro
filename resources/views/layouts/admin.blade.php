<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('page-title', 'Панель управления') · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
</head>
<body class="admin-body dark:bg-gray-950">
    <div class="overlay" id="overlay"></div>
    <div class="admin-app">
        @include('partials.sidebar')
        <main class="admin-main">
            <header class="admin-topbar">
                <div class="admin-topbar-inner">
                    <div class="admin-title-wrap"><button id="sidebarToggle" class="admin-mobile-toggle" aria-label="Открыть меню"><i class="fas fa-bars"></i></button><h2 class="admin-title">@yield('page-title', 'Панель управления')</h2></div>
                    <div class="admin-topbar-actions">
                        @if(($availableClubs ?? collect())->count() > 1)
                            <form method="POST" action="{{ route('clubs.switch') }}">@csrf
                                <select name="club_id" aria-label="Активный клуб" onchange="this.form.submit()" class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                                    @foreach($availableClubs as $membership)
                                        <option value="{{ $membership->club_id }}" @selected($currentClub?->id === $membership->club_id)>{{ $membership->club->name }}</option>
                                    @endforeach
                                </select>
                            </form>
                        @endif
                        <label class="admin-search"><i class="fas fa-search"></i><input type="search" placeholder="Поиск..."></label>
                        <button class="admin-icon-button" aria-label="Уведомления"><i class="far fa-bell"></i><span class="dot"></span></button>
                    </div>
                </div>
            </header>
            <div class="admin-content">@yield('content')</div>
        </main>
    </div>
    @stack('scripts')
    <script>
        (()=>{const sidebar=document.getElementById('adminSidebar'),overlay=document.getElementById('overlay'),toggle=document.getElementById('sidebarToggle'),userButton=document.getElementById('userMenuBtn'),userMenu=document.getElementById('userMenu');const closeSidebar=()=>{sidebar?.classList.remove('active');overlay?.classList.remove('active')};toggle?.addEventListener('click',()=>{sidebar?.classList.toggle('active');overlay?.classList.toggle('active')});overlay?.addEventListener('click',closeSidebar);userButton?.addEventListener('click',event=>{event.stopPropagation();userMenu?.classList.toggle('active')});document.addEventListener('click',event=>{if(!event.target.closest('.admin-user'))userMenu?.classList.remove('active')});document.addEventListener('keydown',event=>{if(event.key==='Escape'){closeSidebar();userMenu?.classList.remove('active')}})})();
    </script>
</body>
</html>
