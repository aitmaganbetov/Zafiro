@extends('layouts.admin')
@section('page-title', __('Услуги'))

@section('content')
@php($field = 'w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-white')
<div class="p-6 lg:p-8">
    <header class="mb-6">
        <h3 class="mb-2 text-2xl font-bold text-gray-900 dark:text-white">{{ __('Услуги') }}</h3>
        <p class="max-w-3xl text-sm text-gray-600 dark:text-gray-400">{{ __('То, что клуб продаёт кроме товаров и игрового времени: PlayStation по часам, распечатка, ламинирование… Услуги пробиваются в кассе вместе с товарами (категория «Услуги»), без склада, и попадают в чек, смену и X/Z-отчёт.') }}</p>
    </header>

    @if(session('status'))<div class="mb-5 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300" role="status">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="mb-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300" role="alert">{{ $errors->first() }}</div>@endif

    @if($canCreate)
    <form method="POST" action="{{ route('services.store') }}" class="mb-6 grid gap-3 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900 md:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_auto] md:items-end">
        @csrf
        <div><label for="service-name" class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('Название') }}</label><input id="service-name" name="name" value="{{ old('name') }}" required maxlength="150" placeholder="{{ __('Например: PS5 — 1 час') }}" class="{{ $field }}"></div>
        <div><label for="service-price" class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('Цена') }}</label><input id="service-price" name="price" type="number" min="0" step="0.01" value="{{ old('price') }}" required class="{{ $field }}"></div>
        <div><label for="service-unit" class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('Единица') }} <span class="font-normal">{{ __('необязательно') }}</span></label><input id="service-unit" name="unit" value="{{ old('unit') }}" maxlength="30" placeholder="{{ __('час, стр., шт.') }}" class="{{ $field }}"></div>
        <button type="submit" class="brand-button rounded-lg px-4 py-2 text-sm"><i class="fas fa-plus"></i> {{ __('Добавить услугу') }}</button>
    </form>
    @endif

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
        <table class="w-full min-w-[640px] text-left text-sm">
            <thead class="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-gray-800 dark:text-gray-400">
                <tr><th class="px-4 py-3">{{ __('Название') }}</th><th class="px-4 py-3">{{ __('Цена') }}</th><th class="px-4 py-3">{{ __('Единица') }}</th><th class="px-4 py-3">{{ __('Статус') }}</th><th class="px-4 py-3"><span class="sr-only">{{ __('Действия') }}</span></th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse($services as $service)
                    <tr>
                        @if($canUpdate)
                            <td class="px-4 py-3" colspan="4">
                                <form method="POST" action="{{ route('services.update', $service) }}" id="service-{{ $service->id }}" class="grid gap-2 md:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_auto] md:items-center">
                                    @csrf @method('PATCH')
                                    <input name="name" value="{{ $service->name }}" required maxlength="150" aria-label="{{ __('Название') }}" class="{{ $field }}">
                                    <input name="price" type="number" min="0" step="0.01" value="{{ $service->price }}" required aria-label="{{ __('Цена') }}" class="{{ $field }}">
                                    <input name="unit" value="{{ $service->unit }}" maxlength="30" aria-label="{{ __('Единица') }}" class="{{ $field }}">
                                    <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($service->is_active) class="brand-check rounded border-gray-300">{{ __('В продаже') }}</label>
                                </form>
                            </td>
                        @else
                            <td class="px-4 py-3 font-medium text-gray-900 dark:text-white">{{ $service->name }}</td>
                            <td class="px-4 py-3">{{ number_format((float) $service->price, 2, ',', ' ') }}</td>
                            <td class="px-4 py-3">{{ $service->unit ?? '—' }}</td>
                            <td class="px-4 py-3">{{ $service->is_active ? __('В продаже') : __('Выключена') }}</td>
                        @endif
                        <td class="whitespace-nowrap px-4 py-3 text-right">
                            @if($canUpdate)<button type="submit" form="service-{{ $service->id }}" class="text-sm font-medium text-green-700 hover:underline dark:text-green-400">{{ __('Сохранить') }}</button>@endif
                            @if($canDelete)
                                <form method="POST" action="{{ route('services.destroy', $service) }}" class="ml-3 inline" onsubmit="return confirm(@js(__('Удалить услугу «:service»? Прошлые чеки не изменятся.', ['service' => $service->name])))">@csrf @method('DELETE')<button type="submit" class="text-sm font-medium text-red-600 hover:underline dark:text-red-400">{{ __('Удалить') }}</button></form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-10 text-center text-gray-500 dark:text-gray-400">{{ __('Услуг пока нет. Добавьте первую: например, «PS5 — 1 час» или «Распечатка, 1 стр.».') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $services->links() }}
</div>
@endsection
