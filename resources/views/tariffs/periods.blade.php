{{-- Days and times of the week (validity or sale period of a tariff): $field — the form field, $periods — the rows. --}}
<div class="tariff-period-list space-y-3" data-period-list>
    @foreach($periods as $periodIndex => $period)
        <div class="tariff-validity-period" data-period>
            <label class="text-xs text-gray-500">{{ __('С') }}<select name="{{ $field }}[{{ $periodIndex }}][start_day]" required class="mt-1 w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800">@foreach($weekdays as $day => $dayName)<option value="{{ $day }}" @selected(($period['start_day'] ?? 'mon') === $day)>{{ $dayName }}</option>@endforeach</select></label>
            <label class="text-xs text-gray-500">{{ __('Время') }}<input name="{{ $field }}[{{ $periodIndex }}][start_time]" type="time" value="{{ $period['start_time'] ?? '00:00' }}" required class="mt-1 w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800"></label>
            <label class="text-xs text-gray-500">{{ __('До') }}<select name="{{ $field }}[{{ $periodIndex }}][end_day]" required class="mt-1 w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800">@foreach($weekdays as $day => $dayName)<option value="{{ $day }}" @selected(($period['end_day'] ?? 'sun') === $day)>{{ $dayName }}</option>@endforeach</select></label>
            <label class="text-xs text-gray-500">{{ __('Время') }}<input name="{{ $field }}[{{ $periodIndex }}][end_time]" type="time" value="{{ $period['end_time'] ?? '23:59' }}" required class="mt-1 w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800"></label>
            <button type="button" data-add-period class="brand-period-add mb-1 grid h-9 w-9 place-items-center rounded-lg" aria-label="{{ __('Добавить период') }}"><i class="fas fa-plus"></i></button>
            <button type="button" data-remove-period class="mb-1 grid h-9 w-9 place-items-center rounded-lg text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/20" aria-label="{{ __('Удалить период') }}"><i class="fas fa-trash"></i></button>
        </div>
    @endforeach
</div>
<template data-period-template>
    <div class="tariff-validity-period" data-period>
        <label class="text-xs text-gray-500">{{ __('С') }}<select name="{{ $field }}[__INDEX__][start_day]" required class="mt-1 w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800">@foreach($weekdays as $day => $dayName)<option value="{{ $day }}">{{ $dayName }}</option>@endforeach</select></label>
        <label class="text-xs text-gray-500">{{ __('Время') }}<input name="{{ $field }}[__INDEX__][start_time]" type="time" value="00:00" required class="mt-1 w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800"></label>
        <label class="text-xs text-gray-500">{{ __('До') }}<select name="{{ $field }}[__INDEX__][end_day]" required class="mt-1 w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800">@foreach($weekdays as $day => $dayName)<option value="{{ $day }}">{{ $dayName }}</option>@endforeach</select></label>
        <label class="text-xs text-gray-500">{{ __('Время') }}<input name="{{ $field }}[__INDEX__][end_time]" type="time" value="23:59" required class="mt-1 w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800"></label>
        <button type="button" data-add-period class="brand-period-add mb-1 grid h-9 w-9 place-items-center rounded-lg" aria-label="{{ __('Добавить период') }}"><i class="fas fa-plus"></i></button>
        <button type="button" data-remove-period class="mb-1 grid h-9 w-9 place-items-center rounded-lg text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/20" aria-label="{{ __('Удалить период') }}"><i class="fas fa-trash"></i></button>
    </div>
</template>
