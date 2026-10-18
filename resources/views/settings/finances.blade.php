@extends('settings.layout')

@section('settings-section', 'finances')

@php
    // Подразделы страницы: якоря в левом меню настроек.
    $settingsAnchors = ['currency-settings' => __('Валюта'), 'loyalty' => __('Накопительная скидка'), 'topup-bonus' => __('Бонусы за пополнение')];
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

<x-settings.card id="loyalty">
    <h5 class="mb-1 text-lg font-semibold text-gray-900 dark:text-white">{{ __('Накопительная скидка') }}</h5>
    <p class="mb-4 max-w-2xl text-sm text-gray-500 dark:text-gray-400">{{ __('Клиент получает скидку, когда его траты в клубе достигают порога. Считаются тарифы, поминутное время и заказы в агенте, оплаченные с основного баланса. Скидка действует на тарифы с отметкой «Применять скидку»; если у клиента есть персональная скидка, применяется большая.') }}</p>

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
