@extends('settings.layout')

@section('settings-section', 'finances')

@section('settings-content')
<h4 class="mb-4 text-xl font-semibold text-gray-900 dark:text-white">{{ __('Финансы') }}</h4>
<div class="grid grid-cols-1 gap-4 md:grid-cols-2">
    <div>
        <label for="currency" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Валюта') }}</label>
        <select id="currency" name="currency" class="brand-focus w-full rounded-lg border border-gray-300 bg-gray-50 px-4 py-2 text-gray-900 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
            <option value="KZT" @selected(($settings['finances']['currency'] ?? 'KZT') === 'KZT')>KZT</option>
            <option value="RUB" @selected(($settings['finances']['currency'] ?? 'KZT') === 'RUB')>RUB</option>
            <option value="$" @selected(($settings['finances']['currency'] ?? 'KZT') === '$')>$</option>
        </select>
    </div>
</div>
@endsection
