@extends('layouts.admin')

@section('page-title', __('Заказы'))

@section('content')
@php
    $statusLabels = ['new' => __('Новые'), 'done' => __('Выданные'), 'cancelled' => __('Отменённые'), 'all' => __('Все')];
    $badge = ['new' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200', 'done' => 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200', 'cancelled' => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300'];
    $badgeText = ['new' => __('Новый'), 'done' => __('Выдан'), 'cancelled' => __('Отменён')];
    $money = fn ($value) => number_format((float) $value, 2, ',', ' ');
@endphp
<div class="p-6 lg:p-8" data-orders data-state-url="{{ route('shop-orders.state') }}" data-new="{{ $newCount }}" data-latest="{{ $latest }}">
    <header class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h3 class="mb-2 text-2xl font-bold text-gray-900 dark:text-white">{{ __('Заказы') }}</h3>
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('Товары, которые клиенты заказали в агенте и оплатили с баланса. Отнесите заказ к ПК и отметьте «Выдан».') }}</p>
        </div>
        <nav class="flex flex-wrap gap-2" aria-label="{{ __('Статус заказов') }}">
            @foreach($statusLabels as $key => $label)
                <a href="{{ route('shop-orders.index', ['status' => $key]) }}" class="rounded-lg border px-3 py-2 text-sm {{ $status === $key ? 'border-transparent brand-button' : 'border-gray-300 text-gray-700 dark:border-gray-700 dark:text-gray-300' }}" @if($status === $key) aria-current="page" @endif>
                    {{ $label }}@if($key === 'new' && $newCount > 0) <span class="ml-1 rounded-full bg-red-600 px-2 text-xs font-bold text-white">{{ $newCount }}</span>@endif
                </a>
            @endforeach
        </nav>
    </header>

    @if(session('status'))<div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300" role="alert">{{ $errors->first() }}</div>@endif

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @forelse($orders as $order)
            <article class="flex flex-col rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <div class="mb-3 flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-lg font-bold text-gray-900 dark:text-white">{{ __('ПК :computer', ['computer' => $order->computer_name ?? '—']) }}</p>
                        <p class="truncate text-sm text-gray-600 dark:text-gray-400">{{ __('Заказ №:id', ['id' => $order->id]) }} · {{ $order->created_at->format('d.m H:i') }} · {{ $order->customer?->name ?? '—' }}</p>
                    </div>
                    <span class="shrink-0 rounded-full px-2 py-1 text-xs font-semibold {{ $badge[$order->status] ?? '' }}">{{ $badgeText[$order->status] ?? $order->status }}</span>
                </div>
                <ul class="mb-3 flex-1 divide-y divide-gray-100 text-sm dark:divide-gray-800">
                    @foreach($order->items as $line)
                        <li class="flex justify-between gap-3 py-1.5 text-gray-800 dark:text-gray-200"><span class="min-w-0">{{ $line['name'] ?? '—' }} <span class="font-semibold">× {{ (int) ($line['quantity'] ?? 0) }}</span></span><span class="shrink-0 text-gray-600 dark:text-gray-400">{{ $money($line['total'] ?? 0) }}</span></li>
                    @endforeach
                </ul>
                <p class="mb-3 flex justify-between text-sm font-semibold text-gray-900 dark:text-white"><span>{{ __('Итого, оплачено с баланса') }}</span><span>{{ $money($order->amount) }}</span></p>
                @if($order->status === 'new')
                    <div class="flex flex-wrap gap-2">
                        @if($currentMembership->hasPermission('orders.update'))<form method="POST" action="{{ route('shop-orders.done', $order) }}" class="flex-1">@csrf<button type="submit" class="brand-button w-full rounded-lg px-4 py-2 text-sm"><i class="fas fa-check"></i>{{ __('Выдан') }}</button></form>@endif
                        @if($currentMembership->hasPermission('orders.delete'))<form method="POST" action="{{ route('shop-orders.cancel', $order) }}" data-confirm="{{ __('Отменить заказ №:id? Деньги вернутся на счёт клиента, товар — на склад.', ['id' => $order->id]) }}">@csrf<button type="submit" class="rounded-lg border border-red-300 px-4 py-2 text-sm text-red-700 dark:border-red-800 dark:text-red-300">{{ __('Отменить') }}</button></form>@endif
                    </div>
                @else
                    <p class="text-xs text-gray-500">{{ $badgeText[$order->status] ?? '' }}: {{ $order->handler?->name ?? '—' }}, {{ $order->handled_at?->format('d.m H:i') }}</p>
                @endif
            </article>
        @empty
            <p class="rounded-xl border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500 md:col-span-2 xl:col-span-3 dark:border-gray-700">{{ $status === 'new' ? __('Новых заказов нет.') : __('Заказов нет.') }}</p>
        @endforelse
    </div>

    <div class="mt-6">{{ $orders->links() }}</div>
</div>
@endsection

@push('scripts')
<script>
(() => {
    const page = document.querySelector('[data-orders]');
    document.querySelectorAll('form[data-confirm]').forEach((form) => form.addEventListener('submit', (event) => {
        if (!window.confirm(form.dataset.confirm)) event.preventDefault();
    }));
    // A new order appears without reloading by hand: check every 15 s, reload when something changed.
    setInterval(async () => {
        if (document.hidden) return;
        try {
            const response = await fetch(page.dataset.stateUrl, { headers: { Accept: 'application/json' } });
            if (!response.ok) return;
            const state = await response.json();
            if (String(state.new) !== page.dataset.new || state.latest > Number(page.dataset.latest)) window.location.reload();
        } catch (error) {
            // No connection: try again next time.
        }
    }, 15000);
})();
</script>
@endpush
