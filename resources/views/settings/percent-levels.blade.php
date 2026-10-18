{{--
    Levels "from a threshold — a percent" of the finance settings: $field (loyalty_levels,
    topup_bonus_levels), $threshold (spent, amount), $thresholdLabel, $percentLabel, $inputClass.
    Rows are added from the <template> by the script of the finance page.
--}}
@php
    $levels = old($field, $settings['finances'][$field] ?? []);
    $levels = is_array($levels) ? array_values($levels) : [];
    $levelError = $errors->first($field) ?: $errors->first($field.'.*');
    $removeButton = 'h-10 w-10 rounded-lg text-gray-400 transition hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-900/20';
@endphp
@if ($levelError)<div class="mb-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">{{ $levelError }}</div>@endif

<div class="max-w-2xl" data-percent-levels="{{ $field }}" data-next-index="{{ count($levels) }}">
    <div class="mb-2 hidden grid-cols-[1fr_1fr_2.5rem] gap-3 text-xs font-semibold uppercase text-gray-500 sm:grid dark:text-gray-400">
        <span>{{ $thresholdLabel }}</span>
        <span>{{ $percentLabel }}</span>
    </div>
    {{-- Without levels the field still comes, so removing the last level is saved. --}}
    <input type="hidden" name="{{ $field }}" value="">
    <div class="space-y-3" data-levels-list>
        @foreach ($levels as $index => $level)
            <div class="grid grid-cols-[1fr_1fr_2.5rem] items-center gap-3" data-level>
                <input type="number" name="{{ $field }}[{{ $index }}][{{ $threshold }}]" value="{{ $level[$threshold] ?? '' }}" min="0.01" step="0.01" required aria-label="{{ $thresholdLabel }}" class="{{ $inputClass }}">
                <input type="number" name="{{ $field }}[{{ $index }}][percent]" value="{{ $level['percent'] ?? '' }}" min="0.01" max="100" step="0.01" required aria-label="{{ $percentLabel }}" class="{{ $inputClass }}">
                <button type="button" data-remove-level class="{{ $removeButton }}" title="{{ __('Удалить уровень') }}" aria-label="{{ __('Удалить уровень') }}"><i class="fas fa-trash"></i></button>
            </div>
        @endforeach
    </div>
    <p class="text-sm text-gray-500 dark:text-gray-400 {{ $levels === [] ? '' : 'hidden' }}" data-levels-empty>{{ __('Уровней пока нет — добавьте первый.') }}</p>
    <button type="button" data-add-level class="mt-3 inline-flex items-center gap-2 rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-100 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">
        <i class="fas fa-plus"></i>{{ __('Добавить уровень') }}
    </button>

    <template>
        <div class="grid grid-cols-[1fr_1fr_2.5rem] items-center gap-3" data-level>
            <input type="number" data-name="{{ $threshold }}" min="0.01" step="0.01" required aria-label="{{ $thresholdLabel }}" class="{{ $inputClass }}">
            <input type="number" data-name="percent" min="0.01" max="100" step="0.01" required aria-label="{{ $percentLabel }}" class="{{ $inputClass }}">
            <button type="button" data-remove-level class="{{ $removeButton }}" title="{{ __('Удалить уровень') }}" aria-label="{{ __('Удалить уровень') }}"><i class="fas fa-trash"></i></button>
        </div>
    </template>
</div>
