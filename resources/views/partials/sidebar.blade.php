<aside id="adminSidebar" class="sidebar">
    <div class="sidebar-brand"><img class="sidebar-brand-mark" src="{{ asset('images/logo.svg') }}" alt=""><div><strong>Zafiro</strong><small>{{ __('Управление клубом') }}</small></div><button type="button" class="sidebar-close" data-sidebar-close aria-label="{{ __('Закрыть меню') }}"><i class="fas fa-xmark" aria-hidden="true"></i></button></div>
    <nav class="sidebar-nav">
        <span class="sidebar-label">{{ __('Рабочее пространство') }}</span>
        <a href="{{ route('dashboard') }}" class="sidebar-link {{ request()->routeIs('dashboard') ? 'active' : '' }}"><i class="fas fa-chart-pie"></i><span>{{ __('Админпанель') }}</span></a>
        <a href="{{ route('cash-register.index') }}" class="sidebar-link {{ request()->routeIs('cash-register.*') ? 'active' : '' }}"><i class="fas fa-cash-register"></i><span>{{ __('Касса') }}</span></a>
        <a href="{{ route('reservations.index') }}" class="sidebar-link {{ request()->routeIs('reservations.*') ? 'active' : '' }}"><i class="fas fa-calendar-check"></i><span>{{ __('Брони') }}</span></a>
        <a href="{{ route('users.index') }}" class="sidebar-link {{ request()->routeIs('users.*') ? 'active' : '' }}"><i class="fas fa-users"></i><span>{{ __('Пользователи') }}</span></a>
        <span class="sidebar-label">{{ __('Учёт') }}</span>
        <a href="{{ route('warehouse') }}" class="sidebar-link {{ request()->routeIs('warehouse*') ? 'active' : '' }}"><i class="fas fa-boxes-stacked"></i><span>{{ __('Склад') }}</span></a>
        <a href="{{ route('club-computers.index') }}" class="sidebar-link {{ request()->routeIs('club-computers.index') ? 'active' : '' }}"><i class="fas fa-computer"></i><span>{{ __('Список ПК') }}</span></a>
        <a href="{{ route('activity-logs.index') }}" class="sidebar-link {{ request()->routeIs('activity-logs.*') ? 'active' : '' }}"><i class="fas fa-clock-rotate-left"></i><span>{{ __('Логи') }}</span></a>
        <span class="sidebar-label">{{ __('Система') }}</span>
        <a href="{{ route('settings') }}" class="sidebar-link {{ request()->routeIs('settings*') ? 'active' : '' }}"><i class="fas fa-sliders"></i><span>{{ __('Настройки') }}</span></a>
        <a href="{{ route('news.index') }}" class="sidebar-link {{ request()->routeIs('news.*') ? 'active' : '' }}"><i class="fas fa-newspaper"></i><span>{{ __('Новости') }}</span></a>
        <a href="{{ route('billing.show') }}" class="sidebar-link {{ request()->routeIs('billing.*') ? 'active' : '' }}"><i class="fas fa-credit-card"></i><span>{{ __('Подписка') }}</span></a>
        @if(Auth::user()->isSuperadmin() && ! isset($superadminViewing))
            <a href="{{ route('superadmin.dashboard') }}" class="sidebar-link"><i class="fas fa-shield-halved"></i><span>{{ __('Кабинет суперадмина') }}</span></a>
        @endif
        <details class="sidebar-group" @if(request()->routeIs('club-map.*', 'tariffs.*')) open @endif>
            <summary class="sidebar-link"><i class="fas fa-layer-group"></i><span>{{ __('Зоны и тарифы') }}</span></summary>
            <div class="sidebar-subnav">
                <a href="{{ route('club-map.index') }}" class="sidebar-link {{ request()->routeIs('club-map.*') ? 'active' : '' }}"><i class="fas fa-map-location-dot"></i><span>{{ __('Зоны') }}</span></a>
                <a href="{{ route('tariffs.index') }}" class="sidebar-link {{ request()->routeIs('tariffs.*') ? 'active' : '' }}"><i class="fas fa-tags"></i><span>{{ __('Тарифы') }}</span></a>
            </div>
        </details>
    </nav>
    <div class="sidebar-footer"><div class="sidebar-user-mini"><span>{{ mb_substr(Auth::user()->name,0,1) }}</span><div><strong>{{ Auth::user()->name }}</strong><small>{{ ['owner' => __('Владелец'), 'admin' => __('Администратор'), 'manager' => __('Менеджер'), 'cashier' => __('Кассир'), 'operator' => __('Оператор')][$currentMembership->role] ?? $currentMembership->role }}</small></div></div><form action="{{ route('auth.logout') }}" method="POST">@csrf<button title="{{ __('Выйти') }}"><i class="fas fa-arrow-right-from-bracket"></i></button></form></div>
</aside>



