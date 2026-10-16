@extends('layouts.superadmin')
@section('page-title', __('Telegram-бот'))

@section('content')
<p class="mb-4 text-sm text-gray-600 dark:text-gray-400">{{ __('Через этого бота клиенты получают новый пароль, когда его сбрасывают в клубе. Клиент один раз привязывает Telegram в агенте на ПК (Профиль → Telegram).') }}</p>
@if(session('status'))<div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">{{ session('status') }}</div>@endif
@if($errors->any())<div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">{{ $errors->first() }}</div>@endif

<div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
    @if($bot)
        <p class="mb-3 text-sm text-gray-700 dark:text-gray-300">{{ __('Подключён бот') }} <a href="https://t.me/{{ $bot }}" class="font-semibold text-violet-700 hover:underline" rel="noopener" target="_blank">{{ '@'.$bot }}</a> · {{ trans_choice('привязан :count клиент|привязано :count клиента|привязано :count клиентов', $linked) }}</p>
    @else
        <p class="mb-3 text-sm text-amber-700">{{ __('Бот не подключён: новый пароль показывается сотруднику на экране.') }}</p>
    @endif
    <form method="POST" action="{{ route('superadmin.telegram.update') }}" class="flex flex-wrap items-end gap-3" autocomplete="off">
        @csrf
        <label class="min-w-0 flex-1 basis-80"><span class="mb-1 block text-xs font-medium text-gray-600">{{ __('Токен бота от @BotFather') }}</span>
            <input type="password" name="token" required maxlength="100" autocomplete="off" class="w-full rounded-lg border border-gray-300 px-3 py-2 font-mono text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-white"></label>
        <button type="submit" class="brand-button rounded-lg px-4 py-2 text-sm">{{ $bot ? __('Заменить бота') : __('Подключить бота') }}</button>
    </form>
    @if($bot)
        <form method="POST" action="{{ route('superadmin.telegram.destroy') }}" class="mt-3" onsubmit="return confirm(@js(__('Отключить бота? Новые пароли будут показываться на экране.')))">@csrf @method('DELETE')
            <button type="submit" class="text-sm text-red-600 hover:underline">{{ __('Отключить бота') }}</button>
        </form>
    @endif
</div>
@endsection
