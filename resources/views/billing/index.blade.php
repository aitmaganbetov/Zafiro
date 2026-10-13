@extends('layouts.admin')

@section('page-title', 'Подписка')

@section('content')
<div class="max-w-3xl p-6 lg:p-8">
    <h3 class="mb-6 text-xl font-semibold text-gray-900 dark:text-white">Подписка клуба «{{ $currentClub->name }}»</h3>
    @if($subscription)
        <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div><dt class="text-sm text-gray-500">План</dt><dd class="mt-1 font-medium text-gray-900 dark:text-white">{{ $subscription->plan->name }}</dd></div>
            <div><dt class="text-sm text-gray-500">Статус</dt><dd class="mt-1 font-medium text-gray-900 dark:text-white">{{ $subscription->status === 'trialing' ? 'Пробный период' : ($subscription->status === 'active' ? 'Активна' : 'Не активна') }}</dd></div>
            @if($subscription->status === 'trialing' && $subscription->trial_ends_at)
                <div><dt class="text-sm text-gray-500">Пробный период до</dt><dd class="mt-1 font-medium text-gray-900 dark:text-white">{{ $subscription->trial_ends_at->format('d.m.Y H:i') }}</dd></div>
            @endif
            @if($subscription->current_period_ends_at)
                <div><dt class="text-sm text-gray-500">Оплачено до</dt><dd class="mt-1 font-medium text-gray-900 dark:text-white">{{ $subscription->current_period_ends_at->format('d.m.Y') }}</dd></div>
            @endif
        </dl>
    @else
        <p class="text-sm text-gray-600 dark:text-gray-300">Для клуба ещё не оформлена подписка.</p>
    @endif
    <p class="mt-8 text-sm text-gray-500">Онлайн-оплата появится после настройки платёжного провайдера.</p>
</div>
@endsection