@extends('layouts.admin')

@section('page-title', __('Работники'))

@section('content')
@php
    $field = 'min-h-10 rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-white';
@endphp
<div class="p-6 lg:p-8">
    <header class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h3 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('Работники') }}</h3>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('Сотрудники клуба. Добавить можно пользователя, который уже зарегистрирован в Zafiro, или отправить ссылку-приглашение.') }}</p>
        </div>
        <a href="{{ route('users.index') }}" class="text-sm font-medium text-green-700 hover:underline dark:text-green-400">{{ __('Клиенты →') }}</a>
    </header>

    @if(session('status'))<div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300" role="alert">{{ $errors->first() }}</div>@endif
    @if(session('invite_url'))<div class="mb-4 flex gap-2"><input id="club-invite-url" readonly value="{{ session('invite_url') }}" class="min-w-0 flex-1 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white"><button type="button" data-copy-invite class="min-h-10 rounded-lg border border-gray-300 px-4 text-sm font-medium dark:border-gray-700">{{ __('Копировать ссылку') }}</button></div>@endif

    @if($currentMembership->hasPermission('workers.create'))
    <div class="mb-6 grid gap-4 lg:grid-cols-2">
        <form method="POST" action="{{ route('workers.store') }}" class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            @csrf
            <h4 class="mb-3 font-semibold text-gray-900 dark:text-white">{{ __('Добавить зарегистрированного пользователя') }}</h4>
            <div class="flex flex-wrap items-center gap-2">
                <div class="flex"><span class="inline-flex items-center rounded-l-lg border border-r-0 border-gray-300 bg-gray-100 px-3 text-sm text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300">+7</span><input name="phone" type="tel" inputmode="numeric" maxlength="16" required value="{{ substr(preg_replace('/\D+/', '', (string) old('phone', '')), -10) }}" placeholder="700 123 45 67" aria-label="{{ __('Телефон пользователя') }}" class="{{ $field }} w-40 rounded-l-none"></div>
                <select name="role" required aria-label="{{ __('Роль') }}" class="{{ $field }}">@foreach($assignable as $role => $label)<option value="{{ $role }}" @selected(old('role', 'cashier') === $role)>{{ $label }}</option>@endforeach</select>
                <button type="submit" class="brand-button min-h-10 px-4"><i class="fas fa-user-plus"></i>{{ __('Добавить') }}</button>
            </div>
        </form>
        <form action="{{ route('club-invitations.store') }}" method="POST" class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            @csrf
            <h4 class="mb-3 font-semibold text-gray-900 dark:text-white">{{ __('Пригласить по ссылке') }}</h4>
            <div class="flex flex-wrap items-center gap-2">
                <input name="email" type="email" value="{{ old('email') }}" placeholder="{{ __('Email (необязательно)') }}" aria-label="{{ __('Email приглашённого') }}" class="{{ $field }}">
                <select name="role" required aria-label="{{ __('Роль приглашённого') }}" class="{{ $field }}">@foreach($assignable as $role => $label)<option value="{{ $role }}" @selected($role === 'operator')>{{ $label }}</option>@endforeach</select>
                <button type="submit" class="min-h-10 rounded-lg border border-gray-300 px-4 text-sm font-medium dark:border-gray-700"><i class="fas fa-link"></i> {{ __('Создать ссылку') }}</button>
            </div>
        </form>
    </div>
    @endif

    <p class="mb-3 text-sm text-gray-600 dark:text-gray-400">
        @foreach($shiftLimits as $role => $shift)
            <span class="mr-4">{{ $role === 'cashier' ? __('Кассиров на смене') : __('Операторов на смене') }}: <strong>{{ $shift['count'] }}{{ $shift['limit'] ? ' / '.$shift['limit'] : '' }}</strong></span>
        @endforeach
        <a href="{{ route('settings.club') }}" class="text-green-700 hover:underline dark:text-green-400">{{ __('Лимит в настройках клуба') }}</a>
    </p>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
        <table class="w-full min-w-[720px] text-left text-sm">
            <thead class="bg-gray-50 text-xs uppercase text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                <tr><th class="px-5 py-3 font-semibold">{{ __('Имя') }}</th><th class="px-5 py-3 font-semibold">{{ __('Номер') }}</th><th class="px-5 py-3 font-semibold">{{ __('Роль') }}</th><th class="px-5 py-3 font-semibold">{{ __('Смена') }}</th><th class="px-5 py-3"><span class="sr-only">{{ __('Действия') }}</span></th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach($workers as $worker)
                    @php($manageable = $worker->role !== 'owner' && $worker->user_id !== auth()->id() && ($worker->role !== 'admin' || $currentMembership->role === 'owner'))
                    <tr class="text-gray-700 dark:text-gray-300">
                        <td class="px-5 py-3 font-medium text-gray-900 dark:text-white">{{ $worker->user?->name }}</td>
                        <td class="whitespace-nowrap px-5 py-3">{{ $worker->user?->phone }}</td>
                        <td class="px-5 py-3">
                            @if($manageable && $currentMembership->hasPermission('workers.update'))
                                <form method="POST" action="{{ route('workers.update', $worker) }}" class="flex items-center gap-2">@csrf @method('PATCH')
                                    <select name="role" aria-label="{{ __('Роль') }}" class="{{ $field }}" onchange="this.form.requestSubmit()">@foreach($assignable as $role => $label)<option value="{{ $role }}" @selected($worker->role === $role)>{{ $label }}</option>@endforeach</select>
                                </form>
                            @else
                                {{ \App\Support\ClubRoles::label($worker->role) }}
                            @endif
                        </td>
                        <td class="px-5 py-3">@if(in_array($worker->user_id, $onShift, true))<span class="rounded-full bg-green-100 px-2 py-1 text-xs font-medium text-green-800 dark:bg-green-900/30 dark:text-green-300">{{ __('На смене') }}</span>@else<span class="text-gray-400">—</span>@endif</td>
                        <td class="px-5 py-3 text-right">
                            {{-- Rights of the employee: the owner, or an admin for non-admins (PermissionController). --}}
                            @if($manageable && in_array($currentMembership->role, ['owner', 'admin'], true))
                                <a href="{{ route('settings.permissions.edit', $worker) }}" class="mr-3 text-sm font-medium text-green-700 hover:underline dark:text-green-400">{{ __('Права') }}</a>
                            @endif
                            @if($manageable && $currentMembership->hasPermission('workers.delete'))
                                <form method="POST" action="{{ route('workers.destroy', $worker) }}" class="inline" onsubmit="return confirm(@js(__('Убрать из сотрудников? Аккаунт останется клиентом клуба.')))">@csrf @method('DELETE')<button type="submit" class="text-sm text-red-600 hover:underline">{{ __('Убрать') }}</button></form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $workers->links() }}</div>
</div>
@endsection

@push('scripts')
<script>
document.querySelector('[data-copy-invite]')?.addEventListener('click', async () => {
    await navigator.clipboard.writeText(document.getElementById('club-invite-url').value);
});
</script>
@endpush
