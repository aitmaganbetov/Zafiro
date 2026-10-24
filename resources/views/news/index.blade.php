@extends($layout)

@section('page-title', __('Новости'))

@section('content')
@php
    $field = 'w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-white';
    $label = 'mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400';
    // In a club — by the employee's rights; the superadmin's platform news — always.
    $mayNews = fn (string $action): bool => $layout !== 'layouts.admin' || ($currentMembership?->hasPermission('news.'.$action) ?? false);
@endphp
{{-- The superadmin layout pads its content itself. --}}
<div class="{{ $layout === 'layouts.admin' ? 'p-6 lg:p-8' : '' }}">
    <header class="mb-6">
        <h3 class="mb-2 text-2xl font-bold text-gray-900 dark:text-white">{{ __('Новости') }}</h3>
        <p class="text-sm text-gray-600 dark:text-gray-400">{{ $intro }}</p>
    </header>

    @if(session('status'))<div class="mb-5 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="mb-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300" role="alert">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif

    @if($mayNews('create'))
    <form method="POST" action="{{ route($routes['store']) }}" enctype="multipart/form-data" class="mb-8 grid gap-3 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
        @csrf
        <h4 class="font-semibold text-gray-900 dark:text-white">{{ __('Новая новость') }}</h4>
        <div><label for="news-title" class="{{ $label }}">{{ __('Заголовок') }}</label><input id="news-title" name="title" maxlength="150" required value="{{ old('title') }}" class="{{ $field }}"></div>
        <div><label for="news-body" class="{{ $label }}">{{ __('Текст') }}</label><textarea id="news-body" name="body" rows="5" maxlength="5000" required class="{{ $field }}">{{ old('body') }}</textarea></div>
        <div class="flex flex-wrap items-end gap-4">
            <div><label for="news-image" class="{{ $label }}">{{ __('Картинка (PNG или JPG, до 2 МБ)') }}</label><input id="news-image" type="file" name="image" accept="image/png,image/jpeg" class="text-sm text-gray-700 dark:text-gray-300"></div>
            <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300"><input type="hidden" name="is_published" value="0"><input type="checkbox" name="is_published" value="1" checked> {{ __('Опубликовать сразу') }}</label>
            <button type="submit" class="brand-button rounded-lg px-4 py-2 text-sm">{{ __('Добавить новость') }}</button>
        </div>
    </form>
    @endif

    <div class="space-y-3">
        @forelse($items as $item)
            <details class="rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                <summary class="flex cursor-pointer items-center gap-4 p-4">
                    @if($item->imageUrl())<img src="{{ $item->imageUrl() }}" alt="" class="h-14 w-24 flex-none rounded-lg object-cover">@endif
                    <span class="min-w-0 flex-1">
                        <strong class="block truncate text-gray-900 dark:text-white">{{ $item->title }}</strong>
                        <small class="text-gray-500 dark:text-gray-400">{{ $item->created_at->format('d.m.Y H:i') }}</small>
                    </span>
                    <span class="rounded-full px-2 py-1 text-xs font-medium {{ $item->is_published ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300' : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400' }}">{{ $item->is_published ? __('Опубликована') : __('Черновик') }}</span>
                </summary>
                <div class="grid gap-3 border-t border-gray-100 p-4 dark:border-gray-800">
                    @if($mayNews('update'))
                    <form method="POST" action="{{ route($routes['update'], $item) }}" enctype="multipart/form-data" class="grid gap-3">
                        @csrf @method('PATCH')
                        <div><label for="news-title-{{ $item->id }}" class="{{ $label }}">{{ __('Заголовок') }}</label><input id="news-title-{{ $item->id }}" name="title" maxlength="150" required value="{{ $item->title }}" class="{{ $field }}"></div>
                        <div><label for="news-body-{{ $item->id }}" class="{{ $label }}">{{ __('Текст') }}</label><textarea id="news-body-{{ $item->id }}" name="body" rows="5" maxlength="5000" required class="{{ $field }}">{{ $item->body }}</textarea></div>
                        <div class="flex flex-wrap items-end gap-4">
                            <div><label for="news-image-{{ $item->id }}" class="{{ $label }}">{{ __('Новая картинка') }}</label><input id="news-image-{{ $item->id }}" type="file" name="image" accept="image/png,image/jpeg" class="text-sm text-gray-700 dark:text-gray-300"></div>
                            @if($item->image_path)<label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300"><input type="checkbox" name="remove_image" value="1"> {{ __('Убрать картинку') }}</label>@endif
                            <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300"><input type="hidden" name="is_published" value="0"><input type="checkbox" name="is_published" value="1" @checked($item->is_published)> {{ __('Опубликована') }}</label>
                            <button type="submit" class="brand-button rounded-lg px-4 py-2 text-sm">{{ __('Сохранить') }}</button>
                        </div>
                    </form>
                    @endif
                    @if($mayNews('delete'))
                    <form method="POST" action="{{ route($routes['destroy'], $item) }}" onsubmit="return confirm(@js(__('Удалить новость?')))">
                        @csrf @method('DELETE')
                        <button type="submit" class="text-sm text-red-600 hover:underline">{{ __('Удалить новость') }}</button>
                    </form>
                    @endif
                </div>
            </details>
        @empty
            <p class="rounded-xl border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">{{ __('Новостей пока нет.') }}</p>
        @endforelse
    </div>
    <div class="mt-4">{{ $items->links() }}</div>
</div>
@endsection
