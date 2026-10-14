@extends('layouts.superadmin')
@section('page-title', $club->name)

@section('content')
@php($card = 'rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-900')
@php($input = 'rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100')
@php($roles = ['owner' => __('Владелец'), 'admin' => __('Администратор'), 'manager' => __('Менеджер'), 'cashier' => __('Кассир'), 'operator' => __('Оператор')])
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <a href="{{ route('superadmin.clubs.index') }}" class="text-sm text-gray-600 hover:underline">{{ __('← Клубы') }}</a>
    <form method="POST" action="{{ route('superadmin.clubs.view', $club) }}">@csrf
        <button type="submit" class="rounded-lg bg-violet-700 px-4 py-2 text-sm font-medium text-white hover:bg-violet-800"><i class="fas fa-eye mr-1" aria-hidden="true"></i>{{ __('Открыть панель клуба (просмотр)') }}</button>
    </form>
</div>

<div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
    <section class="{{ $card }}" aria-labelledby="club-info-title">
        <h3 id="club-info-title" class="mb-3 font-semibold text-gray-900 dark:text-white">{{ __('Клуб') }}</h3>
        <dl class="space-y-2 text-sm">
            <div class="flex justify-between gap-3"><dt class="text-gray-500">{{ __('Адрес') }}</dt><dd class="font-mono">{{ $club->slug }}</dd></div>
            <div class="flex justify-between gap-3"><dt class="text-gray-500">{{ __('Создан') }}</dt><dd class="tabular-nums">{{ $club->created_at->format('d.m.Y') }}</dd></div>
            <div class="flex justify-between gap-3"><dt class="text-gray-500">{{ __('Часовой пояс') }}</dt><dd>{{ $club->timezone ?? config('app.timezone') }}</dd></div>
            <div class="flex justify-between gap-3"><dt class="text-gray-500">{{ __('ПК в сети') }}</dt><dd class="tabular-nums">{{ (int) $computers->online }} / {{ (int) $computers->total }}</dd></div>
        </dl>
    </section>

    <section class="{{ $card }} xl:col-span-2" aria-labelledby="subscription-title">
        <div class="mb-3 flex flex-wrap items-center gap-3">
            <h3 id="subscription-title" class="font-semibold text-gray-900 dark:text-white">{{ __('Подписка') }}</h3>
            @include('superadmin.clubs.subscription-badge', ['subscription' => $club->subscription])
        </div>
        <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">
            {{ __('Тариф: :plan', ['plan' => $club->subscription?->plan?->name ?? '—']) }}
            @if($club->subscription?->current_period_ends_at) · {{ __('оплачено до :date', ['date' => $club->subscription->current_period_ends_at->format('d.m.Y')]) }}@endif
            @if($club->subscription?->status === 'trialing' && $club->subscription->trial_ends_at) · {{ __('пробный период до :date', ['date' => $club->subscription->trial_ends_at->format('d.m.Y')]) }}@endif
        </p>
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <form method="POST" action="{{ route('superadmin.clubs.extend', $club) }}" class="space-y-2 rounded-lg bg-gray-50 p-4 dark:bg-gray-800">@csrf
                <h4 class="text-sm font-medium text-gray-900 dark:text-white">{{ __('Продлить') }}</h4>
                <label class="block text-sm"><span class="mb-1 block text-gray-600">{{ __('Дней') }}</span><input type="number" name="days" min="1" max="3650" value="30" required class="w-full {{ $input }}"></label>
                @if($club->subscription === null)
                    <label class="block text-sm"><span class="mb-1 block text-gray-600">{{ __('Тариф') }}</span><select name="saas_plan_id" class="w-full {{ $input }}">@foreach($plans->where('is_active', true) as $plan)<option value="{{ $plan->id }}">{{ $plan->name }}</option>@endforeach</select></label>
                @endif
                <button type="submit" class="brand-button w-full rounded-lg px-4 py-2 text-sm">{{ __('Продлить подписку') }}</button>
            </form>
            @if($club->subscription)
                <form method="POST" action="{{ route('superadmin.clubs.plan', $club) }}" class="space-y-2 rounded-lg bg-gray-50 p-4 dark:bg-gray-800">@csrf
                    <h4 class="text-sm font-medium text-gray-900 dark:text-white">{{ __('Сменить тариф') }}</h4>
                    <select name="saas_plan_id" class="w-full {{ $input }}" aria-label="{{ __('Тариф') }}">
                        @foreach($plans as $plan)<option value="{{ $plan->id }}" @selected($club->subscription->saas_plan_id === $plan->id)>{{ $plan->name }}@unless($plan->is_active) ({{ __('выключен') }})@endunless</option>@endforeach
                    </select>
                    <button type="submit" class="w-full rounded-lg border border-gray-300 px-4 py-2 text-sm">{{ __('Сохранить тариф') }}</button>
                </form>
                @if($club->subscription->isActive())
                    <form method="POST" action="{{ route('superadmin.clubs.suspend', $club) }}" class="space-y-2 rounded-lg bg-red-50 p-4 dark:bg-red-950" onsubmit="return confirm({{ Js::from(__('Приостановить подписку клуба :club? Клуб потеряет доступ к панели и агентам.', ['club' => $club->name])) }})">@csrf
                        <h4 class="text-sm font-medium text-red-900 dark:text-red-200">{{ __('Приостановить') }}</h4>
                        <p class="text-xs text-red-800 dark:text-red-300">{{ __('Клуб потеряет доступ к панели и агентам ПК, пока подписку не продлят.') }}</p>
                        <button type="submit" class="w-full rounded-lg bg-red-600 px-4 py-2 text-sm text-white hover:bg-red-700">{{ __('Приостановить подписку') }}</button>
                    </form>
                @endif
            @endif
        </div>
    </section>
</div>

<section class="{{ $card }} mt-6" aria-labelledby="members-title">
    <h3 id="members-title" class="mb-3 font-semibold text-gray-900 dark:text-white">{{ __('Участники клуба') }}</h3>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500"><th class="px-3 py-2">{{ __('Имя') }}</th><th class="px-3 py-2">{{ __('Телефон') }}</th><th class="px-3 py-2">{{ __('Роль') }}</th><th class="px-3 py-2">{{ __('Статус') }}</th></tr></thead>
            <tbody>
            @foreach($members as $member)
                <tr class="border-t border-gray-100 dark:border-gray-800">
                    <td class="px-3 py-2 text-gray-900 dark:text-white">{{ $member->user?->name ?? '—' }}</td>
                    <td class="px-3 py-2 font-mono text-gray-700 dark:text-gray-300">{{ $member->user?->phone }}</td>
                    <td class="px-3 py-2">{{ $roles[$member->role] ?? $member->role }}</td>
                    <td class="px-3 py-2">@if($member->user?->isBlocked())<span class="rounded-full bg-red-100 px-2 py-1 text-xs text-red-800">{{ __('Заблокирован') }}</span>@else<span class="text-gray-500">{{ __('Активен') }}</span>@endif</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <div class="mt-3">{{ $members->links() }}</div>
</section>

<div class="mt-6 grid grid-cols-1 gap-4 2xl:grid-cols-2">
    <section class="{{ $card }}" aria-labelledby="club-activity-title">
        <div class="mb-3 flex items-center justify-between"><h3 id="club-activity-title" class="font-semibold text-gray-900 dark:text-white">{{ __('Журнал клуба') }}</h3><a href="{{ route('superadmin.logs.index', ['tab' => 'clubs', 'club' => $club->id]) }}" class="text-sm text-violet-700 hover:underline">{{ __('Весь журнал') }}</a></div>
        @include('superadmin.logs.rows', ['logs' => $activity, 'platform' => false])
    </section>
    <section class="{{ $card }}" aria-labelledby="club-platform-title">
        <div class="mb-3 flex items-center justify-between"><h3 id="club-platform-title" class="font-semibold text-gray-900 dark:text-white">{{ __('Действия суперадминов') }}</h3><a href="{{ route('superadmin.logs.index', ['club' => $club->id]) }}" class="text-sm text-violet-700 hover:underline">{{ __('Весь журнал') }}</a></div>
        @include('superadmin.logs.rows', ['logs' => $platformLogs, 'platform' => true])
    </section>
</div>
@endsection
