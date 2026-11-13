@extends('layouts.superadmin')
@section('page-title', __('Реселлеры'))

@php($money = fn ($value) => number_format((float) $value, 0, '.', ' ').' ₸')
@php($field = 'rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100')
@section('content')
<p class="mb-4 text-sm text-gray-600 dark:text-gray-400">{{ __('Реселлеров создаёте вы: они входят в свой кабинет на :host по телефону и паролю. Клуб, который ввёл код реселлера, платит подписку с его скидкой, а реселлер получает свой процент с каждой оплаты.', ['host' => config('app.reseller_domain') ?? url('/reseller')]) }}</p>

<form method="POST" action="{{ route('superadmin.resellers.store') }}" class="mb-6 rounded-xl border border-dashed border-violet-300 bg-white p-4 dark:border-violet-800 dark:bg-gray-900">@csrf
    <h3 class="mb-3 font-semibold text-gray-900 dark:text-white">{{ __('Новый реселлер') }}</h3>
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        <label class="text-sm"><span class="mb-1 block text-gray-600 dark:text-gray-400">{{ __('Имя или компания') }}</span><input type="text" name="name" value="{{ old('name') }}" required maxlength="120" class="{{ $field }} w-full"></label>
        <label class="text-sm"><span class="mb-1 block text-gray-600 dark:text-gray-400">{{ __('Телефон') }}</span><input type="tel" name="phone" value="{{ old('phone') }}" required placeholder="+7 700 123-45-67" class="{{ $field }} w-full"></label>
        <label class="text-sm"><span class="mb-1 block text-gray-600 dark:text-gray-400">{{ __('Пароль') }}</span><input type="text" name="password" minlength="6" autocomplete="new-password" class="{{ $field }} w-full"><span class="mt-1 block text-xs text-gray-500">{{ __('Если аккаунт с этим телефоном уже есть, оставьте пустым: пароль останется прежним.') }}</span></label>
        <label class="text-sm"><span class="mb-1 block text-gray-600 dark:text-gray-400">{{ __('Код реселлера') }}</span><input type="text" name="code" value="{{ old('code') }}" required maxlength="32" autocapitalize="none" spellcheck="false" placeholder="arman-games" class="{{ $field }} w-full"><span class="mt-1 block text-xs text-gray-500">{{ __('Латинские буквы, цифры и дефис, от 3 до 32 символов.') }}</span></label>
        <label class="text-sm"><span class="mb-1 block text-gray-600 dark:text-gray-400">{{ __('Процент реселлера') }}</span><input type="number" name="commission_percent" min="0" max="100" step="0.01" value="{{ old('commission_percent', 0) }}" required class="{{ $field }} w-full"></label>
        <label class="text-sm"><span class="mb-1 block text-gray-600 dark:text-gray-400">{{ __('Скидка клубам, %') }}</span><input type="number" name="discount_percent" min="0" max="100" step="1" value="{{ old('discount_percent', 0) }}" required class="{{ $field }} w-full"></label>
    </div>
    <button type="submit" class="brand-button mt-3 rounded-lg px-4 py-2 text-sm">{{ __('Создать реселлера') }}</button>
</form>

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
                <div class="flex flex-wrap items-baseline gap-3">
                    <p class="text-sm text-gray-700 dark:text-gray-300">{{ __('Заработано :earned, выплачено :paid, к выплате :balance', ['earned' => $money($reseller->earned), 'paid' => $money($reseller->paid_out), 'balance' => $money($balance)]) }}</p>
                    <form method="POST" action="{{ route('superadmin.resellers.destroy', $reseller) }}" onsubmit="return confirm(@js(__('Удалить реселлера :code? Аккаунт останется, клубы отвяжутся, выплаты удалятся.', ['code' => $reseller->code])))">@csrf @method('DELETE')<button type="submit" class="text-sm text-red-600 hover:underline">{{ __('Удалить') }}</button></form>
                </div>
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
