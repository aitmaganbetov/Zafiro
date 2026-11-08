@extends('layouts.admin')

@section('page-title', __('Подписка'))

@section('content')
@php
    $money = fn ($value) => number_format((float) $value, 0, '.', ' ');
    $statuses = ['pending' => __('Ожидает оплаты'), 'paid' => __('Оплачен'), 'canceled' => __('Отменён')];
@endphp
<div class="max-w-3xl p-6 lg:p-8">
    <h3 class="mb-6 text-xl font-semibold text-gray-900 dark:text-white">{{ __('Подписка клуба «:club»', ['club' => $currentClub->name]) }}</h3>
    @if(session('success'))<div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700" role="status">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">{{ $errors->first() }}</div>@endif
    @if($subscription)
        <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div><dt class="text-sm text-gray-500">{{ __('План') }}</dt><dd class="mt-1 font-medium text-gray-900 dark:text-white">{{ __($subscription->plan->name) }}</dd></div>
            <div><dt class="text-sm text-gray-500">{{ __('Статус') }}</dt><dd class="mt-1 font-medium text-gray-900 dark:text-white">{{ ! $subscription->isActive() ? ($subscription->isPastPaidPeriod() ? __('Истекла') : __('Не активна')) : ($subscription->status === 'trialing' ? __('Пробный период') : __('Активна')) }}</dd></div>
            @if($subscription->status === 'trialing' && $subscription->trial_ends_at)
                <div><dt class="text-sm text-gray-500">{{ __('Пробный период до') }}</dt><dd class="mt-1 font-medium text-gray-900 dark:text-white">{{ $subscription->trial_ends_at->format('d.m.Y H:i') }}</dd></div>
            @endif
            @if($subscription->monthlyPrice() !== null)
                <div><dt class="text-sm text-gray-500">{{ __('Стоимость в месяц') }}</dt><dd class="mt-1 font-medium text-gray-900 dark:text-white">{{ __(':price ₸ за ПК', ['price' => $money($subscription->monthlyPrice())]) }} · {{ __(':amount ₸ за :count ПК', ['amount' => $money($subscription->monthlyPrice() * $computers), 'count' => $computers]) }}@if($subscription->discountPercent() > 0) <span class="text-sm font-normal text-gray-500">{{ $subscription->discount_until ? __('со скидкой :percent% до :date', ['percent' => $subscription->discountPercent(), 'date' => $subscription->discount_until->format('d.m.Y')]) : __('со скидкой :percent%', ['percent' => $subscription->discountPercent()]) }}</span>@endif</dd></div>
            @endif
            @if($subscription->current_period_ends_at)
                <div><dt class="text-sm text-gray-500">{{ __('Оплачено до') }}</dt><dd class="mt-1 font-medium text-gray-900 dark:text-white">{{ $subscription->current_period_ends_at->format('d.m.Y') }}</dd></div>
            @endif
        </dl>
    @else
        <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('Для клуба ещё не оформлена подписка.') }}</p>
    @endif

    @if($pending)
        <section class="mt-8 rounded-xl border border-amber-300 bg-amber-50 p-5 dark:border-amber-700 dark:bg-amber-950/40" aria-labelledby="pending-title">
            <h4 id="pending-title" class="font-semibold text-gray-900 dark:text-white">{{ __('Счёт :number ожидает оплаты', ['number' => $pending->number()]) }}</h4>
            <p class="mt-2 text-3xl font-bold text-gray-900 dark:text-white">{{ $money($pending->amount) }} ₸</p>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ __(':plan, :months, :computers ПК × :price ₸', ['plan' => $pending->plan->name, 'months' => trans_choice(':count месяц|:count месяца|:count месяцев', $pending->months), 'computers' => $pending->computers, 'price' => $money($pending->price_per_computer)]) }}@if($pending->discount_percent > 0), {{ __('скидка :percent%', ['percent' => $pending->discount_percent]) }}@endif</p>
            @if($kaspi)
                <ol class="mt-4 list-decimal space-y-1 pl-5 text-sm text-gray-700 dark:text-gray-200">
                    <li>{{ __('Откройте оплату в Kaspi по кнопке ниже.') }}</li>
                    <li>{{ __('Переведите :amount ₸.', ['amount' => $money($pending->amount)]) }}</li>
                    <li>{{ __('В комментарии к платежу укажите номер счёта :number.', ['number' => $pending->number()]) }}</li>
                </ol>
                @if($kaspi['recipient'])<p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ __('Получатель: :recipient', ['recipient' => $kaspi['recipient']]) }}</p>@endif
                <a href="{{ $kaspi['url'] }}" target="_blank" rel="noopener noreferrer" class="mt-4 inline-flex items-center gap-2 rounded-lg bg-[#f14635] px-5 py-2.5 font-semibold text-white hover:bg-[#d93a2b]">{{ __('Оплатить в Kaspi') }}<i class="fas fa-arrow-up-right-from-square text-sm" aria-hidden="true"></i></a>
            @endif
            <p class="mt-4 text-sm text-gray-600 dark:text-gray-300">{{ __('Подписка продлится, как только мы увидим оплату — обычно в течение рабочего дня.') }}</p>
            @if($canPay)
                <form method="POST" action="{{ route('billing.invoices.cancel', $pending) }}" class="mt-3" onsubmit="return confirm(@js(__('Отменить счёт?')))">@csrf
                    <button type="submit" class="text-sm font-medium text-gray-600 underline hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">{{ __('Отменить счёт') }}</button>
                </form>
            @endif
        </section>
    @elseif(! $canPay)
        <p class="mt-8 text-sm text-gray-500">{{ __('Продлить подписку может владелец клуба.') }}</p>
    @elseif($plans->isEmpty() || ! $kaspi)
        <p class="mt-8 text-sm text-gray-500">{{ __('Оплата пока не настроена. Напишите в поддержку Zafiro.') }}</p>
    @else
        <section class="mt-8 rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-900" aria-labelledby="pay-title">
            <h4 id="pay-title" class="font-semibold text-gray-900 dark:text-white">{{ __('Продлить подписку') }}</h4>
            <form method="POST" action="{{ route('billing.invoices.store') }}" class="mt-4 space-y-4" data-billing-form data-computers="{{ $computers }}" data-discount="{{ $discount }}">@csrf
                <div>
                    <label for="saas_plan_id" class="mb-1 block text-sm text-gray-600 dark:text-gray-300">{{ __('Тариф') }}</label>
                    <select id="saas_plan_id" name="saas_plan_id" class="w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                        @foreach($plans as $plan)
                            <option value="{{ $plan->id }}" data-price="{{ (float) $plan->price_monthly }}" @selected($plan->id === $subscription?->saas_plan_id)>{{ $plan->name }} — {{ __(':price ₸ за ПК в месяц', ['price' => $money($plan->price_monthly)]) }}</option>
                        @endforeach
                    </select>
                </div>
                <fieldset>
                    <legend class="mb-1 text-sm text-gray-600 dark:text-gray-300">{{ __('Срок') }}</legend>
                    <div class="flex flex-wrap gap-2">
                        @foreach(\App\Models\SubscriptionInvoice::MONTHS as $months)
                            <label class="cursor-pointer"><input type="radio" name="months" value="{{ $months }}" class="peer sr-only" @checked($loop->first)><span class="block rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-700 peer-checked:border-[#ff0169] peer-checked:bg-[#ff0169]/10 peer-checked:text-[#d90058] peer-focus-visible:ring-2 peer-focus-visible:ring-[#ff0169] dark:border-gray-700 dark:text-gray-200">{{ trans_choice(':count месяц|:count месяца|:count месяцев', $months) }}</span></label>
                        @endforeach
                    </div>
                </fieldset>
                <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('ПК в клубе: :count. Сумма считается за все ПК клуба.', ['count' => $computers]) }}@if($discount > 0) {{ __('Ваша скидка: :percent%.', ['percent' => $discount]) }}@endif</p>
                <p class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('К оплате') }}: <span data-billing-total></span> ₸</p>
                <button type="submit" class="brand-button rounded-lg px-5 py-2 text-sm">{{ __('Выставить счёт') }}</button>
            </form>
        </section>
    @endif

    @if($invoices->isNotEmpty())
        <section class="mt-10" aria-labelledby="invoices-title">
            <h4 id="invoices-title" class="mb-3 font-semibold text-gray-900 dark:text-white">{{ __('Счета') }}</h4>
            <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
                <table class="w-full text-left text-sm">
                    <thead class="bg-gray-50 text-gray-500 dark:bg-gray-800 dark:text-gray-400"><tr><th class="px-4 py-2 font-medium">{{ __('Счёт') }}</th><th class="px-4 py-2 font-medium">{{ __('Дата') }}</th><th class="px-4 py-2 font-medium">{{ __('Срок') }}</th><th class="px-4 py-2 font-medium">{{ __('Сумма') }}</th><th class="px-4 py-2 font-medium">{{ __('Статус') }}</th></tr></thead>
                    <tbody class="divide-y divide-gray-100 text-gray-800 dark:divide-gray-800 dark:text-gray-200">
                        @foreach($invoices as $invoice)
                            <tr><td class="px-4 py-2">{{ $invoice->number() }}</td><td class="px-4 py-2">{{ $invoice->created_at->format('d.m.Y') }}</td><td class="px-4 py-2">{{ trans_choice(':count месяц|:count месяца|:count месяцев', $invoice->months) }}</td><td class="px-4 py-2">{{ $money($invoice->amount) }} ₸</td><td class="px-4 py-2">{{ $statuses[$invoice->status] ?? $invoice->status }}@if($invoice->period_ends_at) · {{ __('до :date', ['date' => $invoice->period_ends_at->format('d.m.Y')]) }}@endif</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</div>
@endsection

@push('scripts')
<script>
    // The total shown before the bill is opened; the server counts it again the same way.
    document.querySelectorAll('[data-billing-form]').forEach((form) => {
        const total = form.querySelector('[data-billing-total]');
        const update = () => {
            const price = Number(form.querySelector('#saas_plan_id').selectedOptions[0].dataset.price);
            const months = Number(form.querySelector('input[name="months"]:checked').value);
            const sum = Math.round(price * Number(form.dataset.computers) * months * (100 - Number(form.dataset.discount)) / 100);
            total.textContent = sum.toLocaleString('ru-RU').replace(/ /g, ' ');
        };
        form.addEventListener('change', update);
        update();
    });
</script>
@endpush
