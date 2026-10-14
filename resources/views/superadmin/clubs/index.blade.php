@extends('layouts.superadmin')
@section('page-title', __('Клубы'))

@section('content')
<form method="GET" class="mb-4 flex flex-wrap items-end gap-3 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
    <label class="min-w-56 flex-1"><span class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Поиск') }}</span><input type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="{{ __('Название или адрес клуба') }}" class="w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2"></label>
    <label><span class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Подписка') }}</span>
        <select name="subscription" class="rounded-lg border border-gray-300 bg-gray-50 px-3 py-2">
            <option value="">{{ __('Все') }}</option>
            <option value="active" @selected(($filters['subscription'] ?? '') === 'active')>{{ __('Активна') }}</option>
            <option value="inactive" @selected(($filters['subscription'] ?? '') === 'inactive')>{{ __('Неактивна') }}</option>
        </select>
    </label>
    <button type="submit" class="rounded-lg border border-gray-300 px-4 py-2 text-sm">{{ __('Показать') }}</button>
</form>

<div class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
    <table class="w-full text-sm">
        <thead class="border-b border-gray-200 dark:border-gray-700"><tr class="text-left text-gray-600"><th class="px-4 py-3">{{ __('Клуб') }}</th><th class="px-4 py-3">{{ __('Владелец') }}</th><th class="px-4 py-3">{{ __('Тариф') }}</th><th class="px-4 py-3">{{ __('Подписка') }}</th><th class="px-4 py-3 text-right">{{ __('ПК в сети') }}</th><th class="px-4 py-3">{{ __('Создан') }}</th></tr></thead>
        <tbody>
        @forelse($clubs as $club)
            <tr class="border-t border-gray-100 dark:border-gray-800">
                <td class="px-4 py-3"><a href="{{ route('superadmin.clubs.show', $club) }}" class="font-medium text-violet-700 hover:underline">{{ $club->name }}</a><div class="font-mono text-xs text-gray-500">{{ $club->slug }}</div></td>
                <td class="px-4 py-3 text-gray-800 dark:text-gray-200">{{ $club->owner_name ?? '—' }}</td>
                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $club->subscription?->plan?->name ?? '—' }}</td>
                <td class="px-4 py-3">@include('superadmin.clubs.subscription-badge', ['subscription' => $club->subscription])@if($club->subscription?->current_period_ends_at)<div class="mt-1 text-xs text-gray-500">{{ __('до :date', ['date' => $club->subscription->current_period_ends_at->format('d.m.Y')]) }}</div>@endif</td>
                <td class="px-4 py-3 text-right tabular-nums text-gray-700 dark:text-gray-300">{{ (int) $club->online_count }} / {{ (int) $club->computers_count }}</td>
                <td class="px-4 py-3 tabular-nums text-gray-600">{{ $club->created_at->format('d.m.Y') }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="px-4 py-8 text-center text-gray-500">{{ __('Клубы не найдены.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $clubs->links() }}</div>
@endsection
