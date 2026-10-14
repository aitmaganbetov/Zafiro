@extends('settings.layout')

@section('settings-after')
<h4 class="mb-4 text-xl font-semibold text-gray-900 dark:text-white">{{ __('Языки') }}</h4>
@if($errors->has('language') || $errors->has('locale') || $errors->has('code') || $errors->has('name'))
    <div class="mt-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first('language') ?: $errors->first('locale') ?: $errors->first('code') ?: $errors->first('name') }}</div>
@endif

<section class="rounded-lg border border-gray-200 p-4 dark:border-gray-700" aria-labelledby="interface-language-title">
    <h5 id="interface-language-title" class="mb-2 font-semibold text-gray-900 dark:text-white">{{ __('Язык интерфейса') }}</h5>
    <p class="mb-3 text-sm text-gray-600 dark:text-gray-400">{{ __('Все пользователи клуба видят систему на этом языке.') }}</p>
    <form method="POST" action="{{ route('settings.language') }}" class="flex flex-wrap items-end gap-3">
        @csrf
        <label class="min-w-48 flex-1">
            <span class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Язык') }}</span>
            <select name="locale" class="w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-gray-900">
                @foreach($languages->where('is_enabled', true) as $language)
                    <option value="{{ $language->code }}" @selected($clubLocale === $language->code)>{{ $language->name }} ({{ $language->code }})</option>
                @endforeach
            </select>
        </label>
        <button type="submit" class="brand-button rounded-lg px-4 py-2 text-sm">{{ __('Применить') }}</button>
    </form>
</section>

<section class="mt-6 rounded-lg border border-gray-200 p-4 dark:border-gray-700" aria-labelledby="languages-title">
    <h5 id="languages-title" class="mb-2 font-semibold text-gray-900 dark:text-white">{{ __('Языки') }}</h5>
    <p class="mb-3 text-sm text-gray-600 dark:text-gray-400">{{ __('Список языков и переводы общие для всех клубов, их ведёт администрация Zafiro. Выключенные языки нельзя выбрать.') }}</p>
    <ul class="flex flex-wrap gap-2 text-sm">
        @foreach($languages as $language)
            <li class="rounded-full border border-gray-200 px-3 py-1 {{ $language->is_enabled ? 'text-gray-800 dark:text-gray-200' : 'text-gray-400 line-through' }}">{{ $language->name }} <span class="font-mono text-xs text-gray-500">{{ $language->code }}</span></li>
        @endforeach
    </ul>
    @if(auth()->user()?->isSuperadmin())
        <a href="{{ route('superadmin.languages') }}" class="mt-3 inline-block text-sm font-medium text-violet-700 hover:underline">{{ __('Управлять языками и переводами в кабинете суперадмина') }}</a>
    @endif
</section>
@endsection
