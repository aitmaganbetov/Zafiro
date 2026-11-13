<aside id="adminSidebar" class="sidebar">
    <div class="sidebar-brand"><img class="sidebar-brand-mark" src="{{ asset('images/logo.svg') }}" alt=""><div><strong>Zafiro</strong><small>{{ __('Кабинет реселлера') }}</small></div><button type="button" class="sidebar-close" data-sidebar-close aria-label="{{ __('Закрыть меню') }}"><i class="fas fa-xmark" aria-hidden="true"></i></button></div>
    <nav class="sidebar-nav">
        <a href="{{ route('reseller.dashboard') }}" class="sidebar-link active"><i class="fas fa-chart-line"></i><span>{{ __('Мои клубы и доход') }}</span></a>
    </nav>
    <div class="sidebar-footer"><div class="sidebar-user-mini"><span>{{ mb_substr(Auth::user()->name,0,1) }}</span><div><strong>{{ Auth::user()->name }}</strong><small>{{ __('Реселлер') }}</small></div></div><form action="{{ route('auth.logout') }}" method="POST">@csrf<button title="{{ __('Выйти') }}"><i class="fas fa-arrow-right-from-bracket"></i></button></form></div>
</aside>
