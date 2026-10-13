@extends('layouts.admin')

@section('page-title', __('Подписка'))

@section('content')
<div class="max-w-3xl p-6 lg:p-8">
    <h3 class="mb-6 text-xl font-semibold text-gray-900 dark:text-white">{{ __('Подписка клуба «:club»', ['club' => $currentClub->name]) }}</h3>
    @if($subscription)
        <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div><dt class="text-sm text-gray-500">{{ __('План') }}</dt><dd class="mt-1 font-medium text-gray-900 dark:text-white">{{ __($subscription->plan->name) }}</dd></div>
            <div><dt class="text-sm text-gray-500">{{ __('Статус') }}</dt><dd class="mt-1 font-medium text-gray-900 dark:text-white">{{ $subscription->status === 'trialing' ? __('Пробный период') : ($subscription->status === 'active' ? __('Активна') : __('Не активна')) }}</dd></div>
            @if($subscription->status === 'trialing' && $subscription->trial_ends_at)
                <div><dt class="text-sm text-gray-500">{{ __('Пробный период до') }}</dt><dd class="mt-1 font-medium text-gray-900 dark:text-white">{{ $subscription->trial_ends_at->format('d.m.Y H:i') }}</dd></div>
            @endif
            @if($subscription->current_period_ends_at)
                <div><dt class="text-sm text-gray-500">{{ __('Оплачено до') }}</dt><dd class="mt-1 font-medium text-gray-900 dark:text-white">{{ $subscription->current_period_ends_at->format('d.m.Y') }}</dd></div>
            @endif
        </dl>
    @else
        <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('Для клуба ещё не оформлена подписка.') }}</p>
    @endif
    <p class="mt-8 text-sm text-gray-500">{{ __('Онлайн-оплата появится после настройки платёжного провайдера.') }}</p>
</div>
@endsection