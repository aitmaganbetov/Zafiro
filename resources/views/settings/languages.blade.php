@extends('settings.layout')

@section('settings-after')
<h4 class="mb-4 text-xl font-semibold text-gray-900 dark:text-white">{{ __('Языки') }}</h4>
@php($canManageLanguages = in_array($currentMembership->role, ['owner', 'admin'], true))
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
    <p class="mb-3 text-sm text-gray-600 dark:text-gray-400">{{ __('Список языков и переводы общие для всех клубов. Выключенные языки нельзя выбрать.') }}</p>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-600"><th class="px-3 py-2">{{ __('Язык') }}</th><th class="px-3 py-2">{{ __('Код') }}</th><th class="px-3 py-2 text-right">{{ __('Переведено') }}</th><th class="px-3 py-2">{{ __('Показывать') }}</th>@if($canManageLanguages)<th class="px-3 py-2"></th>@endif</tr></thead>
            <tbody>
            @foreach($languages as $language)
                <tr class="border-t border-gray-100">
                    <td class="px-3 py-2 font-medium text-gray-900">{{ $language->name }}@if($clubLocale === $language->code) <span class="ml-1 rounded-full bg-green-100 px-2 py-1 text-xs text-green-800">{{ __('выбран') }}</span>@endif</td>
                    <td class="px-3 py-2 font-mono text-gray-700">{{ $language->code }}</td>
                    <td class="px-3 py-2 text-right tabular-nums text-gray-700">{{ $language->code === 'ru' ? __('исходный') : $language->translated_count.' / '.$translationTotal }}</td>
                    <td class="px-3 py-2">
                        @if($canManageLanguages)
                            <form method="POST" action="{{ route('languages.update', $language) }}">@csrf @method('PATCH')
                                <input type="hidden" name="is_enabled" value="{{ $language->is_enabled ? 0 : 1 }}">
                                <button type="submit" class="rounded-lg border border-gray-300 px-3 py-1 text-sm" aria-label="{{ $language->is_enabled ? __('Выключить язык :name', ['name' => $language->name]) : __('Включить язык :name', ['name' => $language->name]) }}">{{ $language->is_enabled ? __('Да') : __('Нет') }}</button>
                            </form>
                        @else
                            {{ $language->is_enabled ? __('Да') : __('Нет') }}
                        @endif
                    </td>
                    @if($canManageLanguages)
                        <td class="px-3 py-2">
                            <div class="flex flex-wrap items-center justify-end gap-2">
                                <a href="{{ route('languages.translations', $language) }}" class="rounded-lg border border-gray-300 px-3 py-1 text-sm">{{ __('Переводы') }}</a>
                                @unless($language->is_system)
                                    <form method="POST" action="{{ route('languages.destroy', $language) }}" onsubmit="return confirm({{ Js::from(__('Удалить язык :name и все его переводы?', ['name' => $language->name])) }})">@csrf @method('DELETE')
                                        <button type="submit" class="rounded-lg border border-red-200 px-3 py-1 text-sm text-red-700">{{ __('Удалить') }}</button>
                                    </form>
                                @endunless
                            </div>
                        </td>
                    @endif
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    @if($canManageLanguages)
        <form method="POST" action="{{ route('languages.store') }}" class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-[120px_minmax(0,1fr)_auto] sm:items-end">
            @csrf
            <label><span class="mb-1 block text-sm font-medium text-gray-700">{{ __('Код') }}</span><input name="code" value="{{ old('code') }}" required maxlength="16" pattern="[a-z]{2,3}(-[a-z0-9]{2,8})?" placeholder="uz" class="w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 font-mono"></label>
            <label><span class="mb-1 block text-sm font-medium text-gray-700">{{ __('Название') }}</span><input name="name" value="{{ old('name') }}" required maxlength="64" placeholder="{{ __('Например: Oʻzbekcha') }}" class="w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2"></label>
            <button type="submit" class="brand-button rounded-lg px-4 py-2 text-sm">{{ __('Добавить язык') }}</button>
        </form>
    @endif
</section>
@endsection
