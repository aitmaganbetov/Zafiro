@extends('settings.layout')

@section('settings-section', 'finances')

@php
    $currency = $settings['finances']['currency'] ?? 'KZT';
    $loyaltyLevels = old('loyalty_levels', $settings['finances']['loyalty_levels'] ?? []);
    $loyaltyLevels = is_array($loyaltyLevels) ? array_values($loyaltyLevels) : [];
    $levelError = $errors->first('loyalty_levels') ?: $errors->first('loyalty_levels.*');
    $inputClass = 'brand-focus w-full rounded-lg border border-gray-300 bg-gray-50 px-4 py-2 text-gray-900 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100';
@endphp

@section('settings-content')
<h4 class="mb-4 text-xl font-semibold text-gray-900 dark:text-white">{{ __('Финансы') }}</h4>
<div class="grid grid-cols-1 gap-4 md:grid-cols-2">
    <div>
        <label for="currency" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Валюта') }}</label>
        <select id="currency" name="currency" class="{{ $inputClass }}">
            <option value="KZT" @selected($currency === 'KZT')>KZT</option>
            <option value="RUB" @selected($currency === 'RUB')>RUB</option>
            <option value="$" @selected($currency === '$')>$</option>
        </select>
    </div>
</div>

<div class="border-t border-gray-200 pt-6 dark:border-gray-800">
    <h5 class="mb-1 text-lg font-semibold text-gray-900 dark:text-white">{{ __('Накопительная скидка') }}</h5>
    <p class="mb-4 max-w-2xl text-sm text-gray-500 dark:text-gray-400">{{ __('Клиент получает скидку, когда его траты в клубе достигают порога. Считаются тарифы, поминутное время и заказы в агенте, оплаченные с основного баланса. Скидка действует на тарифы с отметкой «Применять скидку»; если у клиента есть персональная скидка, применяется большая.') }}</p>

    <div class="mb-4 inline-flex items-center gap-3 rounded-xl border border-gray-200 p-4 dark:border-gray-700">
        <label for="loyalty_enabled" class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Включить накопительную скидку') }}</label>
        <input type="hidden" name="loyalty_enabled" value="0">
        <input id="loyalty_enabled" type="checkbox" role="switch" name="loyalty_enabled" value="1" class="settings-switch" @checked(old('loyalty_enabled', $settings['finances']['loyalty_enabled'] ?? false))>
    </div>

    @if ($levelError)<div class="mb-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">{{ $levelError }}</div>@endif

    <div class="max-w-2xl">
        <div class="mb-2 hidden grid-cols-[1fr_1fr_2.5rem] gap-3 text-xs font-semibold uppercase text-gray-500 sm:grid dark:text-gray-400">
            <span>{{ __('Потратил от, :currency', ['currency' => $currency]) }}</span>
            <span>{{ __('Скидка, %') }}</span>
        </div>
        {{-- Without levels the field still comes, so removing the last level is saved. --}}
        <input type="hidden" name="loyalty_levels" value="">
        <div id="loyalty-levels" class="space-y-3" data-next-index="{{ count($loyaltyLevels) }}">
            @foreach ($loyaltyLevels as $index => $level)
                <div class="grid grid-cols-[1fr_1fr_2.5rem] items-center gap-3" data-loyalty-level>
                    <input type="number" name="loyalty_levels[{{ $index }}][spent]" value="{{ $level['spent'] ?? '' }}" min="0.01" step="0.01" required aria-label="{{ __('Потратил от, :currency', ['currency' => $currency]) }}" class="{{ $inputClass }}">
                    <input type="number" name="loyalty_levels[{{ $index }}][percent]" value="{{ $level['percent'] ?? '' }}" min="0.01" max="100" step="0.01" required aria-label="{{ __('Скидка, %') }}" class="{{ $inputClass }}">
                    <button type="button" data-remove-loyalty-level class="h-10 w-10 rounded-lg text-gray-400 transition hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-900/20" title="{{ __('Удалить уровень') }}" aria-label="{{ __('Удалить уровень') }}"><i class="fas fa-trash"></i></button>
                </div>
            @endforeach
        </div>
        <p id="loyalty-levels-empty" class="text-sm text-gray-500 dark:text-gray-400 {{ $loyaltyLevels === [] ? '' : 'hidden' }}">{{ __('Уровней пока нет — добавьте первый.') }}</p>
        <button type="button" id="add-loyalty-level" class="mt-3 inline-flex items-center gap-2 rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-100 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">
            <i class="fas fa-plus"></i>{{ __('Добавить уровень') }}
        </button>
    </div>

    <template id="loyalty-level-template">
        <div class="grid grid-cols-[1fr_1fr_2.5rem] items-center gap-3" data-loyalty-level>
            <input type="number" data-name="spent" min="0.01" step="0.01" required aria-label="{{ __('Потратил от, :currency', ['currency' => $currency]) }}" class="{{ $inputClass }}">
            <input type="number" data-name="percent" min="0.01" max="100" step="0.01" required aria-label="{{ __('Скидка, %') }}" class="{{ $inputClass }}">
            <button type="button" data-remove-loyalty-level class="h-10 w-10 rounded-lg text-gray-400 transition hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-900/20" title="{{ __('Удалить уровень') }}" aria-label="{{ __('Удалить уровень') }}"><i class="fas fa-trash"></i></button>
        </div>
    </template>
</div>
@endsection

@push('scripts')
<script>
(() => {
    const list = document.getElementById('loyalty-levels');
    const empty = document.getElementById('loyalty-levels-empty');
    const template = document.getElementById('loyalty-level-template');
    let nextIndex = Number(list.dataset.nextIndex);
    const sync = () => empty.classList.toggle('hidden', list.children.length > 0);

    document.getElementById('add-loyalty-level').addEventListener('click', () => {
        const row = template.content.firstElementChild.cloneNode(true);
        row.querySelectorAll('[data-name]').forEach(input => input.name = `loyalty_levels[${nextIndex}][${input.dataset.name}]`);
        nextIndex++;
        list.appendChild(row);
        row.querySelector('input').focus();
        sync();
    });
    list.addEventListener('click', event => {
        const button = event.target.closest('[data-remove-loyalty-level]');
        if (button) {
            button.closest('[data-loyalty-level]').remove();
            sync();
        }
    });
})();
</script>
@endpush
