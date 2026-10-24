<aside id="adminSidebar" class="sidebar">
    <div class="sidebar-brand"><img class="sidebar-brand-mark" src="{{ asset('images/logo.svg') }}" alt=""><div><strong>Zafiro</strong><small>{{ __('Управление клубом') }}</small></div><button type="button" class="sidebar-close" data-sidebar-close aria-label="{{ __('Закрыть меню') }}"><i class="fas fa-xmark" aria-hidden="true"></i></button></div>
    {{-- Cashiers and operators do not see the admin panel; other pages by the employee's rights (Settings → Rights). --}}
    @php($frontDesk = in_array($currentMembership->role, ['cashier', 'operator'], true))
    @php($can = fn (string $right): bool => $currentMembership->hasPermission($right))
    <nav class="sidebar-nav">
        <span class="sidebar-label">{{ __('Рабочее пространство') }}</span>
        @unless($frontDesk)<a href="{{ route('dashboard') }}" class="sidebar-link {{ request()->routeIs('dashboard') ? 'active' : '' }}"><i class="fas fa-chart-pie"></i><span>{{ __('Админпанель') }}</span></a>@endunless
        @if($can('cash_register'))<a href="{{ route('cash-register.index') }}" class="sidebar-link {{ request()->routeIs('cash-register.*') ? 'active' : '' }}"><i class="fas fa-cash-register"></i><span>{{ __('Касса') }}</span></a>@endif
        <a href="{{ route('club-map.index') }}" class="sidebar-link {{ request()->routeIs('club-map.*') ? 'active' : '' }}"><i class="fas fa-map-location-dot"></i><span>{{ __('Зоны') }}</span></a>
        <a href="{{ route('reservations.index') }}" class="sidebar-link {{ request()->routeIs('reservations.*') ? 'active' : '' }}"><i class="fas fa-calendar-check"></i><span>{{ __('Брони') }}</span></a>
        <a href="{{ route('todos.index') }}" class="sidebar-link {{ request()->routeIs('todos.*') ? 'active' : '' }}"><i class="fas fa-list-check"></i><span>{{ __('To Do List') }}</span></a>
        @php($chatUnread = \App\Http\Controllers\ChatController::unreadTotal())
        <a href="{{ route('chat.index') }}" class="sidebar-link {{ request()->routeIs('chat.*') ? 'active' : '' }}"><i class="fas fa-comments"></i><span>{{ __('Чат') }}</span>@if($chatUnread > 0)<span class="ml-auto rounded-full bg-red-600 px-2 text-xs font-bold text-white">{{ $chatUnread }}</span>@endif</a>
        @php($ordersNew = \App\Models\ShopOrder::newCount())
        <a href="{{ route('shop-orders.index') }}" class="sidebar-link {{ request()->routeIs('shop-orders.*') ? 'active' : '' }}"><i class="fas fa-bag-shopping"></i><span>{{ __('Заказы') }}</span>@if($ordersNew > 0)<span class="ml-auto rounded-full bg-red-600 px-2 text-xs font-bold text-white">{{ $ordersNew }}</span>@endif</a>
        <a href="{{ route('users.index') }}" class="sidebar-link {{ request()->routeIs('users.*') ? 'active' : '' }}"><i class="fas fa-users"></i><span>{{ __('Пользователи') }}</span></a>
        @if($can('workers'))
            <a href="{{ route('workers.index') }}" class="sidebar-link {{ request()->routeIs('workers.*') ? 'active' : '' }}"><i class="fas fa-user-tie"></i><span>{{ __('Работники') }}</span></a>
        @endif
        <span class="sidebar-label">{{ __('Учёт') }}</span>
        <a href="{{ $can('warehouse') ? route('warehouse') : route('stock.index') }}" class="sidebar-link {{ request()->routeIs('warehouse*', 'stock.*') ? 'active' : '' }}"><i class="fas fa-boxes-stacked"></i><span>{{ __('Склад') }}</span></a>
        <a href="{{ route('club-computers.index') }}" class="sidebar-link {{ request()->routeIs('club-computers.index') ? 'active' : '' }}"><i class="fas fa-computer"></i><span>{{ __('Список ПК') }}</span></a>
        @if($can('activity_logs'))<a href="{{ route('activity-logs.index') }}" class="sidebar-link {{ request()->routeIs('activity-logs.*') ? 'active' : '' }}"><i class="fas fa-clock-rotate-left"></i><span>{{ __('Логи') }}</span></a>@endif
        @if($can('settings') || $can('news') || $can('billing') || $can('tariffs') || $currentMembership->role === 'owner')<span class="sidebar-label">{{ __('Система') }}</span>@endif
        @if($can('settings'))<a href="{{ route('settings') }}" class="sidebar-link {{ request()->routeIs('settings*') ? 'active' : '' }}"><i class="fas fa-sliders"></i><span>{{ __('Настройки') }}</span></a>@elseif($currentMembership->role === 'owner')<a href="{{ route('settings.permissions') }}" class="sidebar-link {{ request()->routeIs('settings*') ? 'active' : '' }}"><i class="fas fa-sliders"></i><span>{{ __('Настройки') }}</span></a>@endif
        @if($can('news'))<a href="{{ route('news.index') }}" class="sidebar-link {{ request()->routeIs('news.*') ? 'active' : '' }}"><i class="fas fa-newspaper"></i><span>{{ __('Новости') }}</span></a>@endif
        @if($can('billing'))<a href="{{ route('billing.show') }}" class="sidebar-link {{ request()->routeIs('billing.*') ? 'active' : '' }}"><i class="fas fa-credit-card"></i><span>{{ __('Подписка') }}</span></a>@endif
        @if($can('tariffs'))<a href="{{ route('tariffs.index') }}" class="sidebar-link {{ request()->routeIs('tariffs.*') ? 'active' : '' }}"><i class="fas fa-tags"></i><span>{{ __('Тарифы') }}</span></a>@endif
    </nav>
    <div class="sidebar-footer"><div class="sidebar-user-mini"><span>{{ mb_substr(Auth::user()->name,0,1) }}</span><div><strong>{{ Auth::user()->name }}</strong><small>{{ ['owner' => __('Владелец'), 'admin' => __('Администратор'), 'manager' => __('Менеджер'), 'cashier' => __('Кассир'), 'operator' => __('Оператор')][$currentMembership->role] ?? $currentMembership->role }}</small></div></div><form action="{{ route('auth.logout') }}" method="POST">@csrf<button title="{{ __('Выйти') }}"><i class="fas fa-arrow-right-from-bracket"></i></button></form></div>
</aside>



