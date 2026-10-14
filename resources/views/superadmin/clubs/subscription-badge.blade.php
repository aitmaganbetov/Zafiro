{{-- Статус подписки клуба: $subscription (ClubSubscription|null). --}}
@php($active = $subscription?->isActive() === true)
@php($label = match (true) {
    $subscription === null => __('Нет подписки'),
    $subscription->status === 'trialing' && $active => __('Пробный период'),
    $active => __('Активна'),
    $subscription->status === 'suspended' => __('Приостановлена'),
    $subscription->isPastPaidPeriod() => __('Истекла'),
    default => __('Неактивна'),
})
<span class="rounded-full px-2 py-1 text-xs font-medium {{ $active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">{{ $label }}</span>
