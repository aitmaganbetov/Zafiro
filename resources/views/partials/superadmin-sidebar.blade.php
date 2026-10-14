<aside id="adminSidebar" class="sidebar">
    <div class="sidebar-brand"><img class="sidebar-brand-mark" src="{{ asset('images/logo.svg') }}" alt=""><div><strong>Zafiro</strong><small>{{ __('Кабинет суперадмина') }}</small></div><button type="button" class="sidebar-close" data-sidebar-close aria-label="{{ __('Закрыть меню') }}"><i class="fas fa-xmark" aria-hidden="true"></i></button></div>
    <nav class="sidebar-nav">
        <span class="sidebar-label">{{ __('Платформа') }}</span>
        <a href="{{ route('superadmin.dashboard') }}" class="sidebar-link {{ request()->routeIs('superadmin.dashboard') ? 'active' : '' }}"><i class="fas fa-chart-pie"></i><span>{{ __('Обзор') }}</span></a>
        <a href="{{ route('superadmin.clubs.index') }}" class="sidebar-link {{ request()->routeIs('superadmin.clubs.*') ? 'active' : '' }}"><i class="fas fa-building"></i><span>{{ __('Клубы') }}</span></a>
        <a href="{{ route('superadmin.users.index') }}" class="sidebar-link {{ request()->routeIs('superadmin.users.*') ? 'active' : '' }}"><i class="fas fa-users"></i><span>{{ __('Пользователи') }}</span></a>
        <a href="{{ route('superadmin.plans.index') }}" class="sidebar-link {{ request()->routeIs('superadmin.plans.*') ? 'active' : '' }}"><i class="fas fa-tags"></i><span>{{ __('Тарифы платформы') }}</span></a>
        <span class="sidebar-label">{{ __('Система') }}</span>
        <a href="{{ route('superadmin.logs.index') }}" class="sidebar-link {{ request()->routeIs('superadmin.logs.*') ? 'active' : '' }}"><i class="fas fa-clock-rotate-left"></i><span>{{ __('Журнал') }}</span></a>
        <a href="{{ route('superadmin.languages') }}" class="sidebar-link {{ request()->routeIs('superadmin.languages', 'languages.*') ? 'active' : '' }}"><i class="fas fa-language"></i><span>{{ __('Языки и переводы') }}</span></a>
        @if(Auth::user()->memberships()->withoutGlobalScope('club')->exists())
            <a href="{{ route('dashboard') }}" class="sidebar-link"><i class="fas fa-arrow-left"></i><span>{{ __('Панель своего клуба') }}</span></a>
        @endif
    </nav>
    <div class="sidebar-footer"><div class="sidebar-user-mini"><span>{{ mb_substr(Auth::user()->name,0,1) }}</span><div><strong>{{ Auth::user()->name }}</strong><small>{{ __('Суперадмин') }}</small></div></div><form action="{{ route('auth.logout') }}" method="POST">@csrf<button title="{{ __('Выйти') }}"><i class="fas fa-arrow-right-from-bracket"></i></button></form></div>
</aside>
