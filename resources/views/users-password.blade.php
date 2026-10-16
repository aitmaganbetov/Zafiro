@extends('layouts.admin')

@section('page-title', __('Новый пароль клиента'))

@section('content')
<div class="p-6 lg:p-8">
    <div class="max-w-xl rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
        <h3 class="mb-2 text-xl font-bold text-gray-900 dark:text-white">{{ __('Пароль сброшен: :name', ['name' => $customer->name]) }}</h3>
        @if($sentToTelegram)
            <p class="text-sm text-gray-700 dark:text-gray-300">{{ __('Новый пароль отправлен клиенту в Telegram. Клиент вышел из аккаунта на всех ПК.') }}</p>
        @else
            <p class="mb-3 text-sm text-gray-700 dark:text-gray-300">{{ __('Передайте клиенту новый пароль. Он показывается только сейчас; клиент вышел из аккаунта на всех ПК.') }}</p>
            <p class="mb-3 select-all rounded-lg bg-gray-100 px-4 py-3 text-center font-mono text-2xl tracking-widest text-gray-900 dark:bg-gray-800 dark:text-white">{{ $password }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Чтобы пароль приходил в Telegram, клиенту нужно привязать Telegram в агенте на ПК: Профиль → Telegram.') }}</p>
        @endif
        <a href="{{ route('users.index') }}" class="brand-button mt-5 inline-flex rounded-lg px-4 py-2 text-sm">{{ __('К списку клиентов') }}</a>
    </div>
</div>
@endsection
