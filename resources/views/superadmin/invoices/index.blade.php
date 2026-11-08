@extends('layouts.superadmin')
@section('page-title', __('Оплаты'))

@section('content')
@php
    $money = fn ($value) => number_format((float) $value, 0, '.', ' ');
    $months = fn (int $count) => trans_choice(':count месяц|:count месяца|:count месяцев', $count);
@endphp
<p class="mb-4 text-sm text-gray-600 dark:text-gray-400">{{ __('Клуб выставляет счёт на странице «Подписка» и платит по ссылке Kaspi, указав номер счёта в комментарии. Когда деньги пришли, нажмите «Оплата получена» — подписка клуба продлится на оплаченный срок.') }}</p>

<section class="mb-8" aria-labelledby="pending-title">
    <h3 id="pending-title" class="mb-3 font-semibold text-gray-900 dark:text-white">{{ __('Ожидают оплаты') }}</h3>
    @forelse($pending as $invoice)
        <div class="mb-3 flex flex-wrap items-center gap-4 rounded-xl border border-amber-300 bg-white p-4 dark:border-amber-700 dark:bg-gray-900">
            <div class="min-w-0 flex-1">
                <p class="font-semibold text-gray-900 dark:text-white">{{ $invoice->number() }} · <a href="{{ route('superadmin.clubs.show', $invoice->club_id) }}" class="text-violet-700 hover:underline dark:text-violet-300">{{ $invoice->club->name }}</a></p>
                <p class="text-sm text-gray-600 dark:text-gray-400">{{ $invoice->plan->name }}, {{ $months($invoice->months) }}, {{ $invoice->computers }} {{ __('ПК') }} × {{ $money($invoice->price_per_computer) }} ₸@if($invoice->discount_percent > 0), {{ __('скидка :percent%', ['percent' => $invoice->discount_percent]) }}@endif · {{ $invoice->created_at->format('d.m.Y H:i') }}</p>
            </div>
            <p class="text-xl font-bold text-gray-900 dark:text-white">{{ $money($invoice->amount) }} ₸</p>
            <form method="POST" action="{{ route('superadmin.invoices.pay', $invoice->id) }}" onsubmit="return confirm(@js(__('Деньги по счёту :number пришли? Подписка клуба будет продлена.', ['number' => $invoice->number()])))">@csrf
                <button type="submit" class="brand-button rounded-lg px-4 py-2 text-sm">{{ __('Оплата получена') }}</button>
            </form>
            <form method="POST" action="{{ route('superadmin.invoices.cancel', $invoice->id) }}" onsubmit="return confirm(@js(__('Отменить счёт?')))">@csrf
                <button type="submit" class="rounded-lg border border-gray-300 px-4 py-2 text-sm dark:border-gray-700">{{ __('Отменить') }}</button>
            </form>
        </div>
    @empty
        <p class="text-sm text-gray-500">{{ __('Неоплаченных счетов нет.') }}</p>
    @endforelse
</section>

<form method="POST" action="{{ route('superadmin.invoices.kaspi') }}" class="mb-8 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">@csrf
    <h3 class="mb-1 font-semibold text-gray-900 dark:text-white">{{ __('Оплата через Kaspi') }}</h3>
    <p class="mb-3 text-sm text-gray-600 dark:text-gray-400">{{ __('Ссылка на оплату из Kaspi Pay (Kaspi Business → «Удалённая оплата» или ссылка на QR). Пока ссылки нет, клубы не могут выставить счёт.') }}</p>
    <div class="grid gap-3 sm:grid-cols-2">
        <label class="block text-sm"><span class="mb-1 block text-gray-600 dark:text-gray-300">{{ __('Ссылка на оплату') }}</span><input type="url" name="url" value="{{ old('url', $kaspi['url'] ?? '') }}" placeholder="https://pay.kaspi.kz/pay/…" class="w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 dark:border-gray-700 dark:bg-gray-800"></label>
        <label class="block text-sm"><span class="mb-1 block text-gray-600 dark:text-gray-300">{{ __('Получатель (видят клубы)') }}</span><input type="text" name="recipient" maxlength="200" value="{{ old('recipient', $kaspi['recipient'] ?? '') }}" class="w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 dark:border-gray-700 dark:bg-gray-800"></label>
    </div>
    <button type="submit" class="mt-3 rounded-lg border border-gray-300 px-4 py-2 text-sm dark:border-gray-700">{{ __('Сохранить') }}</button>
</form>

<section aria-labelledby="closed-title">
    <h3 id="closed-title" class="mb-3 font-semibold text-gray-900 dark:text-white">{{ __('История') }}</h3>
    @if($closed->isEmpty())
        <p class="text-sm text-gray-500">{{ __('Пока пусто.') }}</p>
    @else
        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
            <table class="w-full text-left text-sm">
                <thead class="bg-gray-50 text-gray-500 dark:bg-gray-800 dark:text-gray-400"><tr><th class="px-4 py-2 font-medium">{{ __('Счёт') }}</th><th class="px-4 py-2 font-medium">{{ __('Клуб') }}</th><th class="px-4 py-2 font-medium">{{ __('Срок') }}</th><th class="px-4 py-2 font-medium">{{ __('Сумма') }}</th><th class="px-4 py-2 font-medium">{{ __('Статус') }}</th></tr></thead>
                <tbody class="divide-y divide-gray-100 text-gray-800 dark:divide-gray-800 dark:text-gray-200">
                    @foreach($closed as $invoice)
                        <tr>
                            <td class="px-4 py-2">{{ $invoice->number() }}<span class="block text-xs text-gray-500">{{ $invoice->created_at->format('d.m.Y') }}</span></td>
                            <td class="px-4 py-2"><a href="{{ route('superadmin.clubs.show', $invoice->club_id) }}" class="text-violet-700 hover:underline dark:text-violet-300">{{ $invoice->club->name }}</a></td>
                            <td class="px-4 py-2">{{ $invoice->plan->name }}, {{ $months($invoice->months) }}</td>
                            <td class="px-4 py-2">{{ $money($invoice->amount) }} ₸</td>
                            <td class="px-4 py-2">{{ $invoice->status === 'paid' ? __('Оплачен :date', ['date' => $invoice->paid_at->format('d.m.Y')]) : __('Отменён') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $closed->links() }}</div>
    @endif
</section>
@endsection
