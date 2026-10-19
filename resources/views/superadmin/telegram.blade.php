@extends('layouts.superadmin')
@section('page-title', __('Telegram'))

@section('content')
<p class="mb-4 text-sm text-gray-600 dark:text-gray-400">{{ __('Через Telegram Gateway клиенты и сотрудники сами восстанавливают пароль: «Забыли пароль?» на экране входа в агенте и на сайте, код придёт в Telegram на номер аккаунта. Каждый код платный — пополните баланс на gateway.telegram.org.') }}</p>
@if(session('status'))<div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">{{ session('status') }}</div>@endif
@if($errors->any())<div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">{{ $errors->first() }}</div>@endif

<div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
    <h3 class="mb-2 font-semibold text-gray-900 dark:text-white">{{ __('Восстановление пароля (Telegram Gateway)') }}</h3>
    @if($configured)
        <p class="mb-3 text-sm text-gray-700 dark:text-gray-300">{{ __('Telegram Gateway подключён') }} · {{ trans_choice(':count код за 30 дней|:count кода за 30 дней|:count кодов за 30 дней', $sentLastMonth) }}</p>
    @else
        <p class="mb-3 text-sm text-amber-700">{{ __('Telegram Gateway не подключён: восстановить пароль можно только через сотрудника клуба.') }}</p>
    @endif
    <form method="POST" action="{{ route('superadmin.telegram.update') }}" class="flex flex-wrap items-end gap-3" autocomplete="off">
        @csrf
        <label class="min-w-0 flex-1 basis-80"><span class="mb-1 block text-xs font-medium text-gray-600">{{ __('Токен API из настроек аккаунта gateway.telegram.org') }}</span>
            <input type="password" name="token" required maxlength="255" autocomplete="off" class="w-full rounded-lg border border-gray-300 px-3 py-2 font-mono text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-white"></label>
        <button type="submit" class="brand-button rounded-lg px-4 py-2 text-sm">{{ $configured ? __('Заменить токен') : __('Подключить') }}</button>
    </form>
    @if($configured)
        <form method="POST" action="{{ route('superadmin.telegram.destroy') }}" class="mt-3" onsubmit="return confirm(@js(__('Отключить Telegram Gateway? Восстановить пароль самостоятельно будет нельзя.')))">@csrf @method('DELETE')
            <button type="submit" class="text-sm text-red-600 hover:underline">{{ __('Отключить') }}</button>
        </form>
    @endif
</div>

{{-- Оповещения о сбоях: Gateway шлёт только коды подтверждения, поэтому для сообщений нужен свой бот. --}}
<section class="mt-6 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900" aria-labelledby="alerts-title">
    <h3 id="alerts-title" class="mb-2 font-semibold text-gray-900 dark:text-white">{{ __('Оповещения о сбоях') }}</h3>
    <p class="mb-3 text-sm text-gray-600 dark:text-gray-400">{{ __('Бот пишет в чат, когда планировщик молчит больше 5 минут, диск заполнен больше чем на 85%, растёт доля ошибок 5xx, копится очередь или клуб целиком ушёл офлайн, и ещё раз — когда всё наладилось.') }}</p>
    @if($alerts)
        <p class="mb-3 text-sm text-gray-700 dark:text-gray-300">{{ __('Оповещения включены, чат :chat.', ['chat' => $alerts['chat_id']]) }}</p>
    @else
        <ol class="mb-3 list-decimal space-y-1 pl-5 text-sm text-gray-600 dark:text-gray-400">
            <li>{{ __('Создайте бота у @BotFather и скопируйте его токен.') }}</li>
            <li>{{ __('Отправьте боту /start или добавьте его в группу, куда должны приходить оповещения.') }}</li>
            <li>{{ __('Вставьте токен ниже. Чат можно не указывать: возьмём последний, который писал боту.') }}</li>
        </ol>
    @endif
    <form method="POST" action="{{ route('superadmin.telegram.alerts.update') }}" class="flex flex-wrap items-end gap-3" autocomplete="off">
        @csrf
        <label class="min-w-0 flex-1 basis-80"><span class="mb-1 block text-xs font-medium text-gray-600">{{ __('Токен бота от @BotFather') }}</span>
            <input type="password" name="bot_token" required maxlength="255" autocomplete="off" class="w-full rounded-lg border border-gray-300 px-3 py-2 font-mono text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-white"></label>
        <label class="basis-48"><span class="mb-1 block text-xs font-medium text-gray-600">{{ __('ID чата (необязательно)') }}</span>
            <input name="chat_id" maxlength="64" value="{{ old('chat_id', $alerts['chat_id'] ?? '') }}" class="w-full rounded-lg border border-gray-300 px-3 py-2 font-mono text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-white"></label>
        <button type="submit" class="brand-button rounded-lg px-4 py-2 text-sm">{{ $alerts ? __('Сохранить бота') : __('Подключить бота') }}</button>
    </form>
    @if($alerts)
        <div class="mt-3 flex flex-wrap gap-4">
            <form method="POST" action="{{ route('superadmin.telegram.alerts.test') }}">@csrf
                <button type="submit" class="text-sm text-violet-700 hover:underline">{{ __('Отправить проверочное сообщение') }}</button>
            </form>
            <form method="POST" action="{{ route('superadmin.telegram.alerts.destroy') }}" onsubmit="return confirm(@js(__('Отключить оповещения о сбоях?')))">@csrf @method('DELETE')
                <button type="submit" class="text-sm text-red-600 hover:underline">{{ __('Отключить оповещения') }}</button>
            </form>
        </div>
    @endif
</section>
@endsection
