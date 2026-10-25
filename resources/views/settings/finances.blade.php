@extends('settings.layout')

@section('settings-section', 'finances')

@php
    // Подразделы страницы: якоря в левом меню настроек.
    $settingsAnchors = ['currency-settings' => __('Валюта'), 'payment-rules' => __('Оплата времени'), 'loyalty' => __('Накопительная скидка'), 'topup-bonus' => __('Бонусы за пополнение')];
    $discountScope = old('discount_scope', $settings['finances']['discount_scope'] ?? 'tariffs');
    $currency = $settings['finances']['currency'] ?? 'KZT';
    $inputClass = 'brand-focus w-full rounded-lg border border-gray-300 bg-gray-50 px-4 py-2 text-gray-900 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100';
@endphp

@section('settings-content')
<h4 class="text-xl font-semibold text-gray-900 dark:text-white">{{ __('Финансы') }}</h4>
<x-settings.card id="currency-settings" class="grid grid-cols-1 gap-4 md:grid-cols-2">
    <div>
        <label for="currency" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Валюта') }}</label>
        <select id="currency" name="currency" class="{{ $inputClass }}">
            <option value="KZT" @selected($currency === 'KZT')>KZT</option>
            <option value="RUB" @selected($currency === 'RUB')>RUB</option>
            <option value="$" @selected($currency === '$')>$</option>
        </select>
    </div>
</x-settings.card>

<x-settings.card id="payment-rules">
    <h5 class="mb-1 text-lg font-semibold text-gray-900 dark:text-white">{{ __('Оплата времени') }}</h5>
    <p class="mb-4 max-w-2xl text-sm text-gray-500 dark:text-gray-400">{{ __('Как клиенты платят за время в агенте: где действует их скидка (персональная или накопительная), какую часть цены можно оплатить бонусами и можно ли платить сразу с двух балансов. Клиент сам выбирает при покупке: с основного баланса или бонусами.') }}</p>
    <div class="grid max-w-2xl grid-cols-1 gap-4 md:grid-cols-2">
        <div>
            <label for="discount_scope" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Где действует скидка клиента') }}</label>
            <select id="discount_scope" name="discount_scope" class="{{ $inputClass }}">
                <option value="tariffs" @selected($discountScope === 'tariffs')>{{ __('На тарифы с отметкой «Применять скидку»') }}</option>
                <option value="hourly" @selected($discountScope === 'hourly')>{{ __('Только на почасовую оплату (поминутно по цене зоны)') }}</option>
                <option value="all" @selected($discountScope === 'all')>{{ __('Везде: на тарифы с отметкой и на почасовую оплату') }}</option>
            </select>
            @error('discount_scope')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="bonus_max_percent" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Сколько процентов цены можно оплатить бонусами') }}</label>
            <input id="bonus_max_percent" name="bonus_max_percent" type="number" min="0" max="100" step="1" required value="{{ old('bonus_max_percent', $settings['finances']['bonus_max_percent'] ?? 100) }}" class="{{ $inputClass }}">
            <p class="mt-1 text-xs text-gray-500">{{ __('100 — бонусами можно оплатить всю цену, 0 — бонусами за время платить нельзя.') }}</p>
            @error('bonus_max_percent')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>
    </div>
    <div class="mt-4 max-w-2xl rounded-xl border border-gray-200 p-4 dark:border-gray-700">
        <div class="flex items-center gap-3">
            <label for="allow_mixed_payment" class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Разрешить смешанную оплату') }}</label>
            <input type="hidden" name="allow_mixed_payment" value="0">
            <input id="allow_mixed_payment" type="checkbox" role="switch" name="allow_mixed_payment" value="1" class="settings-switch" @checked(old('allow_mixed_payment', $settings['finances']['allow_mixed_payment'] ?? false))>
        </div>
        <p class="mt-2 text-xs text-gray-500">{{ __('Включено — клиент платит за тариф сразу с двух балансов: бонусами (до процента выше), остальное — с основного. Выключено — целиком с одного баланса: основного или бонусного (бонусами — только если можно оплатить 100% цены).') }}</p>
    </div>
    <div class="mt-4 max-w-2xl rounded-xl border border-gray-200 p-4 dark:border-gray-700">
        <div class="flex items-center gap-3">
            <label for="allow_whole_balance" class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Разрешить игру на весь баланс') }}</label>
            <input type="hidden" name="allow_whole_balance" value="0">
            <input id="allow_whole_balance" type="checkbox" role="switch" name="allow_whole_balance" value="1" class="settings-switch" @checked(old('allow_whole_balance', $settings['finances']['allow_whole_balance'] ?? false))>
        </div>
        <p class="mt-2 text-xs text-gray-500">{{ __('Клиент без тарифа нажимает в агенте «На весь баланс» и играет, пока хватает денег на основном балансе, по цене часа своей зоны: 2000 при цене 1000 за час — 2 часа (не больше 12 часов за раз). Ушёл раньше — неиспользованные минуты вернутся на баланс.') }}</p>
    </div>
    <div class="mt-4 max-w-2xl rounded-xl border border-gray-200 p-4 dark:border-gray-700">
        <div class="flex items-center gap-3">
            <label for="auto_billing" class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Поминутная оплата сразу после входа') }}</label>
            <input type="hidden" name="auto_billing" value="0">
            <input id="auto_billing" type="checkbox" role="switch" name="auto_billing" value="1" class="settings-switch" @checked(old('auto_billing', $settings['finances']['auto_billing'] ?? false))>
        </div>
        <p class="mt-2 text-xs text-gray-500">{{ __('Клиент вошёл на ПК и не купил тариф — после паузы начинается поминутная оплата по цене часа зоны с основного баланса. Закончился тариф — поминутная оплата продолжается сразу. Деньги списываются только за сыгранные минуты: при завершении сессии остаток возвращается.') }}</p>
        <label for="auto_billing_pause_minutes" class="mt-3 mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Пауза после входа до начала поминутной оплаты (в минутах)') }}</label>
        <input id="auto_billing_pause_minutes" name="auto_billing_pause_minutes" type="number" min="0" max="60" step="1" value="{{ old('auto_billing_pause_minutes', $settings['finances']['auto_billing_pause_minutes'] ?? 5) }}" class="{{ $inputClass }} max-w-[160px]">
        <p class="mt-1 text-xs text-gray-500">{{ __('Столько минут после входа клиент может бесплатно выбрать тариф. 0 — оплата начинается сразу.') }}</p>
        @error('auto_billing_pause_minutes')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>
</x-settings.card>

<x-settings.card id="loyalty">
    <h5 class="mb-1 text-lg font-semibold text-gray-900 dark:text-white">{{ __('Накопительная скидка') }}</h5>
    <p class="mb-4 max-w-2xl text-sm text-gray-500 dark:text-gray-400">{{ __('Клиент получает скидку, когда его траты в клубе достигают порога. Считаются тарифы, поминутное время и заказы в агенте, оплаченные с основного баланса. Где действует скидка — в блоке «Оплата времени»; если у клиента есть персональная скидка, применяется большая.') }}</p>

    <div class="mb-4 inline-flex items-center gap-3 rounded-xl border border-gray-200 p-4 dark:border-gray-700">
        <label for="loyalty_enabled" class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Включить накопительную скидку') }}</label>
        <input type="hidden" name="loyalty_enabled" value="0">
        <input id="loyalty_enabled" type="checkbox" role="switch" name="loyalty_enabled" value="1" class="settings-switch" @checked(old('loyalty_enabled', $settings['finances']['loyalty_enabled'] ?? false))>
    </div>

    @include('settings.percent-levels', ['field' => 'loyalty_levels', 'threshold' => 'spent', 'thresholdLabel' => __('Потратил от, :currency', ['currency' => $currency]), 'percentLabel' => __('Скидка, %')])
</x-settings.card>

<x-settings.card id="topup-bonus">
    <h5 class="mb-1 text-lg font-semibold text-gray-900 dark:text-white">{{ __('Бонусы за пополнение') }}</h5>
    <p class="mb-4 max-w-2xl text-sm text-gray-500 dark:text-gray-400">{{ __('Когда клиенту пополняют основной баланс на кассе, на бонусный баланс добавляется процент от суммы: по уровню, которого достигла сумма пополнения. Выключено — бонусы не начисляются.') }}</p>

    <div class="mb-4 inline-flex items-center gap-3 rounded-xl border border-gray-200 p-4 dark:border-gray-700">
        <label for="topup_bonus_enabled" class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Включить бонусы за пополнение') }}</label>
        <input type="hidden" name="topup_bonus_enabled" value="0">
        <input id="topup_bonus_enabled" type="checkbox" role="switch" name="topup_bonus_enabled" value="1" class="settings-switch" @checked(old('topup_bonus_enabled', $settings['finances']['topup_bonus_enabled'] ?? false))>
    </div>

    @include('settings.percent-levels', ['field' => 'topup_bonus_levels', 'threshold' => 'amount', 'thresholdLabel' => __('Пополнение от, :currency', ['currency' => $currency]), 'percentLabel' => __('Бонус, %')])
</x-settings.card>
@endsection

@push('scripts')
<script>
// Level tables (cumulative discount, top-up bonus): rows from the table's <template>.
document.querySelectorAll('[data-percent-levels]').forEach(table => {
    const field = table.dataset.percentLevels;
    const list = table.querySelector('[data-levels-list]');
    const empty = table.querySelector('[data-levels-empty]');
    const template = table.querySelector('template');
    let nextIndex = Number(table.dataset.nextIndex);
    const sync = () => empty.classList.toggle('hidden', list.children.length > 0);

    table.querySelector('[data-add-level]').addEventListener('click', () => {
        const row = template.content.firstElementChild.cloneNode(true);
        row.querySelectorAll('[data-name]').forEach(input => input.name = `${field}[${nextIndex}][${input.dataset.name}]`);
        nextIndex++;
        list.appendChild(row);
        row.querySelector('input').focus();
        sync();
    });
    list.addEventListener('click', event => {
        const button = event.target.closest('[data-remove-level]');
        if (button) {
            button.closest('[data-level]').remove();
            sync();
        }
    });
});
</script>
@endpush
