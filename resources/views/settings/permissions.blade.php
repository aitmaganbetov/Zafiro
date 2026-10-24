@extends('settings.layout')

@section('settings-after')
<x-settings.card id="permissions" aria-labelledby="permissions-title">
    <h5 id="permissions-title" class="mb-1 text-lg font-semibold text-gray-900 dark:text-white">{{ __('Права сотрудников') }}</h5>
    <p class="mb-4 max-w-3xl text-sm text-gray-500 dark:text-gray-400">{{ __('Выберите сотрудника, чтобы отметить, что он может делать в каждом разделе. Пока права не меняли, у сотрудника права его роли. Владелец может всё.') }}</p>

    @if($staff->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('В клубе пока нет сотрудников. Добавьте их на странице «Работники».') }}</p>
    @else
        <ul class="divide-y divide-gray-100 rounded-xl border border-gray-200 dark:divide-gray-800 dark:border-gray-800">
            @foreach($staff as $member)
                <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                    <div>
                        <span class="block font-medium text-gray-900 dark:text-white">{{ $member->user->name }}</span>
                        <span class="text-sm text-gray-500 dark:text-gray-400">{{ \App\Support\ClubRoles::label($member->role) }} · {{ $member->user->phone }} · {{ empty($member->permissions) ? __('права по роли') : __('права изменены') }}</span>
                    </div>
                    @if($editable($member))
                        <a href="{{ route('settings.permissions.edit', $member) }}" class="brand-button rounded-xl px-4 py-2 text-sm">{{ __('Настроить права') }}</a>
                    @else
                        <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('Права задаёт владелец') }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</x-settings.card>
@endsection
