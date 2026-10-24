@extends('settings.layout')

@section('settings-after')
<x-settings.card id="permissions" aria-labelledby="permissions-title">
    <h5 id="permissions-title" class="mb-1 text-lg font-semibold text-gray-900 dark:text-white">{{ __('Права сотрудников') }}</h5>
    <p class="mb-4 max-w-3xl text-sm text-gray-500 dark:text-gray-400">{{ __('Отметьте, что может каждый сотрудник. Без изменений сотрудник получает права своей роли; изменённые вами права отмечены рамкой. Владелец может всё.') }}</p>

    @if($staff->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('В клубе пока нет сотрудников. Добавьте их на странице «Работники».') }}</p>
    @else
    <form method="POST" action="{{ route('settings.permissions.update') }}">
        @csrf
        <div class="overflow-x-auto">
            <table class="w-full min-w-[640px] text-left text-sm">
                <thead class="border-b border-gray-200 text-xs text-gray-500 dark:border-gray-800 dark:text-gray-400">
                    <tr>
                        <th class="px-3 py-3 font-semibold uppercase">{{ __('Право') }}</th>
                        @foreach($staff as $member)
                            <th class="px-3 py-3 text-center font-semibold"><span class="block text-gray-900 dark:text-white">{{ $member->user->name }}</span><span class="font-normal">{{ \App\Support\ClubRoles::label($member->role) }}</span></th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach($rights as $right => $info)
                        <tr>
                            <td class="px-3 py-3"><span class="font-medium text-gray-900 dark:text-white">{{ $info['label'] }}</span><span class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">{{ $info['hint'] }}</span></td>
                            @foreach($staff as $member)
                                @php($given = \App\Support\ClubPermissions::given($member, $right))
                                <td class="px-3 py-3 text-center">
                                    <input type="hidden" name="rights[{{ $member->id }}][{{ $right }}]" value="0">
                                    <input type="checkbox" name="rights[{{ $member->id }}][{{ $right }}]" value="1" aria-label="{{ $info['label'] }}: {{ $member->user->name }}" @checked($member->hasPermission($right)) @class(['brand-check h-5 w-5 rounded border-gray-300', 'ring-2 ring-amber-400 ring-offset-2 dark:ring-offset-gray-900' => $given !== null])>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4 flex justify-end">
            <button type="submit" class="brand-button rounded-xl px-5 py-2 text-sm transition">{{ __('Сохранить права') }}</button>
        </div>
    </form>

    @php($custom = $staff->filter(fn ($member) => ! empty($member->permissions)))
    @if($custom->isNotEmpty())
        <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-gray-200 pt-4 text-sm dark:border-gray-800">
            <span class="text-gray-500 dark:text-gray-400">{{ __('Вернуть права по роли:') }}</span>
            @foreach($custom as $member)
                <form method="POST" action="{{ route('settings.permissions.reset', $member) }}">@csrf
                    <button type="submit" class="rounded-lg border border-gray-300 px-3 py-1 text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">{{ $member->user->name }}</button>
                </form>
            @endforeach
        </div>
    @endif
    @endif
</x-settings.card>
@endsection
