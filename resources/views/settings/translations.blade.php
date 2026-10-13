@extends('layouts.admin')

@section('page-title', __('Переводы: :name', ['name' => $language->name]))

@section('content')
<div class="p-6 lg:p-8">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <a href="{{ route('settings.languages') }}" class="text-sm text-gray-600 hover:underline">{{ __('← Языки') }}</a>
            <h3 class="mt-2 text-2xl font-bold text-gray-900">{{ __('Переводы: :name', ['name' => $language->name]) }} <span class="font-mono text-base text-gray-500">({{ $language->code }})</span></h3>
            <p class="mt-1 text-sm text-gray-600">{{ __('Переведено :translated из :total. Пустое поле — используется английский текст, а если его нет — русский.', ['translated' => $translated, 'total' => $total]) }}</p>
        </div>
    </div>

    @if(session('status'))<div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif

    <form method="GET" class="mb-4 flex flex-wrap items-end gap-3 rounded-xl border border-gray-200 bg-white p-4">
        <label class="min-w-56 flex-1"><span class="mb-1 block text-sm font-medium text-gray-700">{{ __('Поиск') }}</span><input type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="{{ __('Текст на русском или перевод') }}" class="w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2"></label>
        <label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" name="missing" value="1" @checked($filters['missing'] ?? false)> {{ __('Только непереведённые') }}</label>
        <button type="submit" class="rounded-lg border border-gray-300 px-4 py-2 text-sm">{{ __('Показать') }}</button>
    </form>

    <form method="POST" action="{{ route('languages.translations.save', $language) }}" class="rounded-xl border border-gray-200 bg-white">
        @csrf @method('PUT')
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="border-b border-gray-200"><tr class="text-left text-gray-600"><th class="w-1/2 px-4 py-3">{{ __('Исходный текст (русский)') }}</th><th class="px-4 py-3">{{ __('Перевод') }}</th></tr></thead>
                <tbody>
                @forelse($rows as $row)
                    <tr class="border-b border-gray-100 align-top">
                        <td class="px-4 py-3 text-gray-900">
                            <label for="t-{{ $row['hash'] }}">{{ $row['source'] }}</label>
                            @if($row['label'] !== $row['source'])<div class="mt-1 font-mono text-xs text-gray-500">{{ $row['label'] }}</div>@endif
                        </td>
                        <td class="px-4 py-3">
                            <textarea id="t-{{ $row['hash'] }}" name="translations[{{ $row['hash'] }}]" rows="{{ mb_strlen($row['source']) > 80 ? 3 : 1 }}" class="w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 {{ $row['value'] === '' ? 'border-amber-300' : '' }}">{{ $row['value'] }}</textarea>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="2" class="px-4 py-8 text-center text-gray-600">{{ __('Ничего не найдено.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-3 p-4">
            <div>{{ $rows->links() }}</div>
            @if($rows->isNotEmpty())<button type="submit" class="brand-button rounded-lg px-5 py-2 text-sm">{{ __('Сохранить переводы на странице') }}</button>@endif
        </div>
    </form>
</div>
@endsection
