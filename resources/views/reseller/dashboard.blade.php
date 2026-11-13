@extends('layouts.reseller')
@section('page-title', __('Мои клубы и доход'))

@php($money = fn ($value) => number_format((float) $value, 0, '.', ' ').' ₸')
@section('content')
<div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
        <div class="text-sm text-gray-500">{{ __('Ваш код') }}</div>
        <div class="mt-1 font-mono text-2xl font-bold text-gray-900 dark:text-white">{{ $reseller->code }}</div>
        <div class="mt-1 text-xs text-gray-500">{{ __('Клуб вводит его в «Настройки → Настройка клуба → Реселлер».') }}</div>
    </div>
    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
        <div class="text-sm text-gray-500">{{ __('Ваш процент с оплат') }}</div>
        <div class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ rtrim(rtrim(number_format((float) $reseller->commission_percent, 2, '.', ''), '0'), '.') }}%</div>
        <div class="mt-1 text-xs text-gray-500">{{ __('Скидка для ваших клубов: :percent%', ['percent' => $reseller->discount_percent]) }}</div>
    </div>
    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
        <div class="text-sm text-gray-500">{{ __('Заработано всего') }}</div>
        <div class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ $money($earned) }}</div>
        <div class="mt-1 text-xs text-gray-500">{{ __('Выплачено: :amount', ['amount' => $money($paidOut)]) }}</div>
    </div>
    <div class="rounded-xl border border-violet-200 bg-violet-50 p-4 dark:border-violet-800 dark:bg-violet-950/40">
        <div class="text-sm text-violet-700 dark:text-violet-300">{{ __('К выплате') }}</div>
        <div class="mt-1 text-2xl font-bold text-violet-900 dark:text-white">{{ $money($earned - $paidOut) }}</div>
    </div>
</div>
@unless($reseller->is_active)<div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800" role="status">{{ __('Ваш код приостановлен: новые клубы не могут его ввести, а оплаты идут без скидки и процента. Свяжитесь с Zafiro.') }}</div>@endunless

<section class="mb-6 rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900" aria-labelledby="reseller-clubs">
    <h3 id="reseller-clubs" class="border-b border-gray-200 px-4 py-3 font-semibold text-gray-900 dark:border-gray-700 dark:text-white">{{ trans_choice(':count клуб|:count клуба|:count клубов', $clubs->total()) }}</h3>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-left text-gray-500"><tr><th class="px-4 py-2">{{ __('Клуб') }}</th><th class="px-4 py-2">{{ __('Подписка') }}</th><th class="px-4 py-2">{{ __('С вами с') }}</th></tr></thead>
            <tbody>
                @forelse($clubs as $club)
                    <tr class="border-t border-gray-100 dark:border-gray-800">
                        <td class="px-4 py-2 text-gray-900 dark:text-white">{{ $club->name }}@if($club->city)<div class="text-xs text-gray-500">{{ \App\Support\Cities::name($club->city) }}</div>@endif</td>
                        <td class="px-4 py-2 text-gray-700 dark:text-gray-300">
                            @php($subscription = $club->subscription)
                            @if($subscription?->status === 'active' && $subscription->current_period_ends_at)
                                {{ __(':plan до :date', ['plan' => $subscription->plan?->name ?? '—', 'date' => $subscription->current_period_ends_at->format('d.m.Y')]) }}
                            @elseif($subscription?->status === 'trialing' && $subscription->trial_ends_at)
                                {{ __('Пробный период до :date', ['date' => $subscription->trial_ends_at->format('d.m.Y')]) }}
                            @else
                                {{ __('Не оплачена') }}
                            @endif
                        </td>
                        <td class="px-4 py-2 text-gray-500">{{ $club->reseller_attached_at?->format('d.m.Y') ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-4 py-6 text-center text-gray-500">{{ __('Пока ни один клуб не ввёл ваш код.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="px-4 py-2">{{ $clubs->links() }}</div>
</section>

<section class="mb-6 rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900" aria-labelledby="reseller-earnings">
    <h3 id="reseller-earnings" class="border-b border-gray-200 px-4 py-3 font-semibold text-gray-900 dark:border-gray-700 dark:text-white">{{ __('Начисления') }}</h3>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-left text-gray-500"><tr><th class="px-4 py-2">{{ __('Дата') }}</th><th class="px-4 py-2">{{ __('Клуб') }}</th><th class="px-4 py-2">{{ __('Оплата клуба') }}</th><th class="px-4 py-2">{{ __('Ваш доход') }}</th></tr></thead>
            <tbody>
                @forelse($earnings as $invoice)
                    <tr class="border-t border-gray-100 dark:border-gray-800">
                        <td class="px-4 py-2 text-gray-500">{{ $invoice->paid_at?->format('d.m.Y') }}</td>
                        <td class="px-4 py-2 text-gray-900 dark:text-white">{{ $invoice->club?->name ?? '—' }}</td>
                        <td class="px-4 py-2 text-gray-700 dark:text-gray-300">{{ $money($invoice->amount) }} · {{ trans_choice(':count месяц|:count месяца|:count месяцев', $invoice->months) }}</td>
                        <td class="px-4 py-2 font-medium text-gray-900 dark:text-white">{{ $money($invoice->reseller_amount) }} <span class="text-xs font-normal text-gray-500">({{ rtrim(rtrim(number_format((float) $invoice->reseller_percent, 2, '.', ''), '0'), '.') }}%)</span></td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-6 text-center text-gray-500">{{ __('Начислений пока нет: они появятся, когда ваши клубы оплатят подписку.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="px-4 py-2">{{ $earnings->links() }}</div>
</section>

@if($payouts->isNotEmpty())
<section class="rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900" aria-labelledby="reseller-payouts">
    <h3 id="reseller-payouts" class="border-b border-gray-200 px-4 py-3 font-semibold text-gray-900 dark:border-gray-700 dark:text-white">{{ __('Выплаты') }}</h3>
    <ul class="divide-y divide-gray-100 text-sm dark:divide-gray-800">
        @foreach($payouts as $payout)
            <li class="flex justify-between gap-4 px-4 py-2"><span class="text-gray-500">{{ $payout->created_at->format('d.m.Y') }}@if($payout->note) · {{ $payout->note }}@endif</span><span class="font-medium text-gray-900 dark:text-white">{{ $money($payout->amount) }}</span></li>
        @endforeach
    </ul>
</section>
@endif
@endsection
