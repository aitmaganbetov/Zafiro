@extends('layouts.superadmin')
@section('page-title', __('Пользователи'))

@section('content')
@php($roles = ['owner' => __('Владелец'), 'admin' => __('Администратор'), 'manager' => __('Менеджер'), 'cashier' => __('Кассир'), 'operator' => __('Оператор')])
<form method="GET" class="mb-4 flex flex-wrap items-end gap-3 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
    <label class="min-w-56 flex-1"><span class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Поиск') }}</span><input type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="{{ __('Имя или телефон') }}" class="w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2"></label>
    <label><span class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Показать') }}</span>
        <select name="filter" class="rounded-lg border border-gray-300 bg-gray-50 px-3 py-2">
            <option value="">{{ __('Все') }}</option>
            <option value="blocked" @selected(($filters['filter'] ?? '') === 'blocked')>{{ __('Заблокированные') }}</option>
            <option value="superadmin" @selected(($filters['filter'] ?? '') === 'superadmin')>{{ __('Суперадмины') }}</option>
        </select>
    </label>
    <button type="submit" class="rounded-lg border border-gray-300 px-4 py-2 text-sm">{{ __('Найти') }}</button>
</form>

<div class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
    <table class="w-full text-sm">
        <thead class="border-b border-gray-200 dark:border-gray-700"><tr class="text-left text-gray-600"><th class="px-4 py-3">{{ __('Пользователь') }}</th><th class="px-4 py-3">{{ __('Клубы и роли') }}</th><th class="px-4 py-3">{{ __('Статус') }}</th><th class="px-4 py-3"></th></tr></thead>
        <tbody>
        @forelse($users as $user)
            @php($self = $user->is(auth()->user()))
            <tr class="border-t border-gray-100 align-top dark:border-gray-800">
                <td class="px-4 py-3"><div class="font-medium text-gray-900 dark:text-white">{{ $user->name }}@if($self) <span class="text-xs text-gray-500">({{ __('вы') }})</span>@endif</div><div class="font-mono text-xs text-gray-500">{{ $user->phone }}</div></td>
                <td class="px-4 py-3">
                    @forelse($user->memberships as $membership)
                        <div><a href="{{ route('superadmin.clubs.show', $membership->club_id) }}" class="text-violet-700 hover:underline">{{ $membership->club?->name }}</a> <span class="text-gray-500">· {{ $roles[$membership->role] ?? $membership->role }}</span></div>
                    @empty
                        <span class="text-gray-400">—</span>
                    @endforelse
                </td>
                <td class="px-4 py-3 space-y-1">
                    @if($user->isSuperadmin())<span class="inline-block rounded-full bg-violet-100 px-2 py-1 text-xs text-violet-800">{{ __('Суперадмин') }}</span>@endif
                    @if($user->isBlocked())<span class="inline-block rounded-full bg-red-100 px-2 py-1 text-xs text-red-800">{{ __('Заблокирован с :date', ['date' => $user->blocked_at->format('d.m.Y')]) }}</span>@endif
                </td>
                <td class="px-4 py-3">
                    @unless($self)
                        <div class="flex flex-wrap justify-end gap-2">
                            @if($user->isBlocked())
                                <form method="POST" action="{{ route('superadmin.users.unblock', $user) }}">@csrf<button type="submit" class="rounded-lg border border-gray-300 px-3 py-1 text-sm">{{ __('Разблокировать') }}</button></form>
                            @elseif(! $user->isSuperadmin())
                                <form method="POST" action="{{ route('superadmin.users.block', $user) }}" onsubmit="return confirm({{ Js::from(__('Заблокировать :user? Он сразу выйдет из всех сеансов и приложений.', ['user' => $user->name])) }})">@csrf<button type="submit" class="rounded-lg border border-red-200 px-3 py-1 text-sm text-red-700">{{ __('Заблокировать') }}</button></form>
                            @endif
                            @if($user->isSuperadmin())
                                <form method="POST" action="{{ route('superadmin.users.revoke', $user) }}" onsubmit="return confirm({{ Js::from(__('Снять с :user права суперадмина?', ['user' => $user->name])) }})">@csrf @method('DELETE')<button type="submit" class="rounded-lg border border-gray-300 px-3 py-1 text-sm">{{ __('Снять права суперадмина') }}</button></form>
                            @elseif(! $user->isBlocked())
                                <form method="POST" action="{{ route('superadmin.users.grant', $user) }}" onsubmit="return confirm({{ Js::from(__('Выдать :user права суперадмина? Он получит доступ ко всем клубам платформы.', ['user' => $user->name])) }})">@csrf<button type="submit" class="rounded-lg border border-violet-200 px-3 py-1 text-sm text-violet-700">{{ __('Сделать суперадмином') }}</button></form>
                            @endif
                        </div>
                    @endunless
                </td>
            </tr>
        @empty
            <tr><td colspan="4" class="px-4 py-8 text-center text-gray-500">{{ __('Пользователи не найдены.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $users->links() }}</div>
@endsection
