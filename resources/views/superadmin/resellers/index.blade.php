@extends('layouts.superadmin')
@section('page-title', __('Реселлеры'))

@php($money = fn ($value) => number_format((float) $value, 0, '.', ' ').' ₸')
@php($field = 'rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100')
@section('content')
<p class="mb-4 text-sm text-gray-600 dark:text-gray-400">{{ __('Реселлеры регистрируются сами на :host и выбирают код. Клуб, который ввёл код, платит подписку со скидкой реселлера, а реселлер получает свой процент с каждой оплаты. Новый реселлер начинает с 0% и без скидки.', ['host' => config('app.reseller_domain') ?? url('/reseller')]) }}</p>

<form method="GET" class="mb-4 flex flex-wrap gap-2">
    <input type="search" name="q" value="{{ $search }}" placeholder="{{ __('Код или имя') }}" class="{{ $field }}">
    <button type="submit" class="rounded-lg border border-gray-300 px-4 py-2 text-sm">{{ __('Найти') }}</button>
</form>

<div class="space-y-4">
    @forelse($resellers as $reseller)
        @php($balance = (float) $reseller->earned - (float) $reseller->paid_out)
        <article class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
            <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                <div>
                    <h3 class="font-semibold text-gray-900 dark:text-white"><span class="font-mono">{{ $reseller->code }}</span> · {{ $reseller->name }}</h3>
                    <p class="text-sm text-gray-500">{{ $reseller->user?->name }} · {{ $reseller->user?->phone }} · {{ trans_choice(':count клуб|:count клуба|:count клубов', $reseller->clubs->count()) }}</p>
                </div>
                <p class="text-sm text-gray-700 dark:text-gray-300">{{ __('Заработано :earned, выплачено :paid, к выплате :balance', ['earned' => $money($reseller->earned), 'paid' => $money($reseller->paid_out), 'balance' => $money($balance)]) }}</p>
            </div>
            <div class="grid gap-4 lg:grid-cols-2">
                <form method="POST" action="{{ route('superadmin.resellers.update', $reseller) }}" class="flex flex-wrap items-end gap-3">@csrf @method('PATCH')
                    <label class="text-sm"><span class="mb-1 block text-gray-600 dark:text-gray-400">{{ __('Процент реселлера') }}</span><input type="number" name="commission_percent" min="0" max="100" step="0.01" value="{{ (float) $reseller->commission_percent }}" required class="{{ $field }} w-28"></label>
                    <label class="text-sm"><span class="mb-1 block text-gray-600 dark:text-gray-400">{{ __('Скидка клубам, %') }}</span><input type="number" name="discount_percent" min="0" max="100" step="1" value="{{ $reseller->discount_percent }}" required class="{{ $field }} w-28"></label>
                    <label class="flex items-center gap-2 pb-2 text-sm text-gray-700 dark:text-gray-300"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($reseller->is_active)> {{ __('Код действует') }}</label>
                    <button type="submit" class="rounded-lg border border-gray-300 px-4 py-2 text-sm">{{ __('Сохранить') }}</button>
                </form>
                <form method="POST" action="{{ route('superadmin.resellers.payout', $reseller) }}" class="flex flex-wrap items-end gap-3">@csrf
                    <label class="text-sm"><span class="mb-1 block text-gray-600 dark:text-gray-400">{{ __('Выплата, ₸') }}</span><input type="number" name="amount" min="0.01" step="0.01" value="{{ $balance > 0 ? round($balance, 2) : '' }}" required class="{{ $field }} w-32"></label>
                    <label class="min-w-0 flex-1 text-sm"><span class="mb-1 block text-gray-600 dark:text-gray-400">{{ __('Комментарий') }}</span><input type="text" name="note" maxlength="255" placeholder="{{ __('Например: Kaspi, ноябрь') }}" class="{{ $field }} w-full"></label>
                    <button type="submit" class="brand-button rounded-lg px-4 py-2 text-sm">{{ __('Записать выплату') }}</button>
                </form>
            </div>
            @if($reseller->clubs->isNotEmpty())
                <details class="mt-3 text-sm">
                    <summary class="cursor-pointer text-gray-600 dark:text-gray-400">{{ __('Клубы реселлера') }}</summary>
                    <ul class="mt-2 divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach($reseller->clubs as $club)
                            <li class="flex items-center justify-between gap-3 py-2">
                                <a href="{{ route('superadmin.clubs.show', $club) }}" class="text-gray-900 hover:underline dark:text-white">{{ $club->name }}</a>
                                <span class="text-gray-500">{{ $club->reseller_attached_at?->format('d.m.Y') }}</span>
                                <form method="POST" action="{{ route('superadmin.resellers.detach', [$reseller, $club]) }}" onsubmit="return confirm(@js(__('Отвязать клуб от реселлера? Новые счета пойдут без его скидки и процента.')))">@csrf @method('DELETE')<button type="submit" class="text-red-600 hover:underline">{{ __('Отвязать') }}</button></form>
                            </li>
                        @endforeach
                    </ul>
                </details>
            @endif
        </article>
    @empty
        <p class="text-sm text-gray-500">{{ __('Реселлеров пока нет.') }}</p>
    @endforelse
</div>
<div class="mt-4">{{ $resellers->links() }}</div>
@endsection
