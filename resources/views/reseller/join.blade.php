@extends('layouts.reseller')
@section('page-title', __('Стать реселлером'))

@section('content')
<div class="max-w-xl rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-900">
    <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">{{ __('Выберите свой код: клубы вводят его в настройках, получают скидку на подписку, а вы — процент с их оплат.') }}</p>
    <form method="POST" action="{{ route('reseller.store') }}" class="space-y-4">@csrf
        <label class="block">
            <span class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Имя или компания') }}</span>
            <input type="text" name="name" value="{{ old('name', $user->name) }}" required maxlength="120" class="w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
        </label>
        <label class="block">
            <span class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Код реселлера') }}</span>
            <input type="text" name="code" value="{{ old('code') }}" required maxlength="32" autocapitalize="none" spellcheck="false" placeholder="arman-games" class="w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
            <span class="mt-1 block text-xs text-gray-500">{{ __('Латинские буквы, цифры и дефис, от 3 до 32 символов.') }}</span>
        </label>
        <button type="submit" class="brand-button rounded-lg px-4 py-2 text-sm">{{ __('Стать реселлером') }}</button>
    </form>
</div>
@endsection
