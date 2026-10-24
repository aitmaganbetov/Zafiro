@extends('settings.layout')

@section('settings-after')
<x-settings.card id="permissions" aria-labelledby="permissions-title">
    <a href="{{ route('settings.permissions') }}" class="mb-3 inline-block text-sm text-gray-600 hover:underline dark:text-gray-400">{{ __('← Все сотрудники') }}</a>
    <h5 id="permissions-title" class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Права: :user', ['user' => $member->user->name]) }}</h5>
    <p class="mb-4 max-w-3xl text-sm text-gray-500 dark:text-gray-400">{{ __('Роль: :role. Отметьте, что сотрудник может в каждом разделе. Отличия от прав роли отмечены рамкой; «—» — такого действия в разделе нет.', ['role' => \App\Support\ClubRoles::label($member->role)]) }}</p>

    <form method="POST" action="{{ route('settings.permissions.update', $member) }}">
        @csrf @method('PUT')
        <div class="overflow-x-auto">
            <table class="w-full min-w-[640px] text-left text-sm">
                <thead class="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-gray-800 dark:text-gray-400">
                    <tr>
                        <th class="px-3 py-3 font-semibold">{{ __('Раздел') }}</th>
                        @foreach($actions as $actionLabel)<th class="px-3 py-3 text-center font-semibold">{{ $actionLabel }}</th>@endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach($sections as $section => $info)
                        <tr>
                            <td class="px-3 py-3 font-medium text-gray-900 dark:text-white">{{ $info['label'] }}</td>
                            @foreach($actions as $action => $actionLabel)
                                <td class="px-3 py-3 text-center">
                                    @isset($info['actions'][$action])
                                        @php($right = $section.'.'.$action)
                                        <input type="hidden" name="rights[{{ $section }}][{{ $action }}]" value="0">
                                        <input type="checkbox" name="rights[{{ $section }}][{{ $action }}]" value="1" title="{{ $info['actions'][$action] }}" aria-label="{{ $info['label'] }}: {{ $actionLabel }} — {{ $info['actions'][$action] }}" @checked($member->hasPermission($right)) @class(['brand-check h-5 w-5 rounded border-gray-300', 'ring-2 ring-amber-400 ring-offset-2 dark:ring-offset-gray-900' => \App\Support\ClubPermissions::given($member, $right) !== null])>
                                        <span class="mt-1 block text-xs text-gray-500 dark:text-gray-400">{{ $info['actions'][$action] }}</span>
                                    @else
                                        <span class="text-gray-400" aria-hidden="true">—</span>
                                    @endisset
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4 flex flex-wrap items-center justify-end gap-3">
            <button type="submit" class="brand-button rounded-xl px-5 py-2 text-sm transition">{{ __('Сохранить права') }}</button>
        </div>
    </form>
    @unless(empty($member->permissions))
        <form method="POST" action="{{ route('settings.permissions.reset', $member) }}" class="mt-3 text-right">@csrf
            <button type="submit" class="text-sm text-gray-600 hover:underline dark:text-gray-400">{{ __('Вернуть права по роли') }}</button>
        </form>
    @endunless
</x-settings.card>
@endsection
