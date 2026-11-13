@extends('settings.layout')

{{-- Что гости видят о клубе в мобильном приложении. --}}
@php($settingsAnchors = [
    'club-about' => __('О клубе'),
    'club-photos' => __('Фотографии'),
    'club-zones' => __('Зоны и оборудование'),
])
@php($input = 'w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100')

@section('settings-after')
<x-settings.card id="club-about" aria-labelledby="club-about-title">
    <h5 id="club-about-title" class="mb-2 font-semibold text-gray-900 dark:text-white">{{ __('О клубе') }}</h5>
    <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">{{ __('Гости видят это на странице клуба в мобильном приложении. Город и адрес — в «Настройка клуба».') }}</p>
    @if($errors->hasAny(['description', 'phone', 'hours', 'instagram']))<div class="mb-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">{{ $errors->first() }}</div>@endif
    <form method="POST" action="{{ route('settings.profile.update') }}" class="grid gap-4 md:grid-cols-3">
        @csrf
        <label class="md:col-span-3">
            <span class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Описание') }}</span>
            <textarea name="description" rows="4" maxlength="2000" class="{{ $input }}" placeholder="{{ __('Чем ваш клуб хорош: зоны, турниры, кухня, парковка…') }}">{{ old('description', $profile['description'] ?? '') }}</textarea>
        </label>
        <label>
            <span class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Телефон') }}</span>
            <input type="tel" name="phone" maxlength="32" value="{{ old('phone', $profile['phone'] ?? '') }}" placeholder="+7 700 000 00 00" class="{{ $input }}">
        </label>
        <label>
            <span class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Часы работы') }}</span>
            <input type="text" name="hours" maxlength="100" value="{{ old('hours', $profile['hours'] ?? '') }}" placeholder="{{ __('Круглосуточно') }}" class="{{ $input }}">
        </label>
        <label>
            <span class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Instagram</span>
            <input type="text" name="instagram" maxlength="64" value="{{ old('instagram', isset($profile['instagram']) ? '@'.$profile['instagram'] : '') }}" placeholder="@club" class="{{ $input }}">
        </label>
        <div class="md:col-span-3 flex justify-end">
            <button type="submit" class="brand-button rounded-lg px-4 py-2 text-sm">{{ __('Сохранить') }}</button>
        </div>
    </form>
</x-settings.card>

<x-settings.card id="club-photos" aria-labelledby="club-photos-title">
    <h5 id="club-photos-title" class="mb-2 font-semibold text-gray-900 dark:text-white">{{ __('Фотографии') }}</h5>
    <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">{{ __('До :count фото зала, зон и входа: JPG, PNG или WebP, до 3 МБ. Первое фото — обложка клуба в списке.', ['count' => \App\Models\ClubPhoto::LIMIT]) }}</p>
    @if($errors->has('photos') || $errors->has('photos.*'))<div class="mb-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">{{ $errors->first('photos') ?: collect($errors->get('photos.*'))->flatten()->first() }}</div>@endif
    @if($photos->isNotEmpty())
        <ul class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
            @foreach($photos as $photo)
                <li class="overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700">
                    <img src="{{ $photo->url() }}" alt="{{ __('Фото клуба') }}" class="aspect-[4/3] w-full object-cover" loading="lazy">
                    <div class="flex items-center justify-between gap-2 px-3 py-2 text-sm">
                        @if($loop->first)
                            <span class="font-medium text-[#d90058]">{{ __('Обложка') }}</span>
                        @else
                            <form method="POST" action="{{ route('settings.photos.cover', $photo->id) }}">@csrf
                                <button type="submit" class="text-gray-600 hover:underline dark:text-gray-300">{{ __('Сделать обложкой') }}</button>
                            </form>
                        @endif
                        <form method="POST" action="{{ route('settings.photos.destroy', $photo->id) }}" onsubmit="return confirm(@js(__('Удалить фото?')))">@csrf @method('DELETE')
                            <button type="submit" class="text-red-600 hover:underline">{{ __('Удалить') }}</button>
                        </form>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
    @if($photos->count() < \App\Models\ClubPhoto::LIMIT)
        <form method="POST" action="{{ route('settings.photos.store') }}" enctype="multipart/form-data" class="flex flex-wrap items-center gap-3">
            @csrf
            <input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple required class="min-w-0 text-sm text-gray-700 dark:text-gray-300">
            <button type="submit" class="brand-button rounded-lg px-4 py-2 text-sm">{{ __('Добавить фото') }}</button>
        </form>
    @endif
</x-settings.card>

<x-settings.card id="club-zones" aria-labelledby="club-zones-title">
    <h5 id="club-zones-title" class="mb-2 font-semibold text-gray-900 dark:text-white">{{ __('Зоны и оборудование') }}</h5>
    <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">{{ __('Гости видят зоны с ценой за час, числом ПК и оборудованием. Процессор, видеокарту и память можно не заполнять — их сообщает агент на ПК (подсказка в поле). Зоны и цены меняются на карте клуба.') }}
        <a href="{{ route('club-map.index') }}" class="font-medium text-[#d90058] hover:underline">{{ __('Карта клуба') }}</a></p>
    @forelse($zones as $zone)
        <form id="zone-{{ $zone->id }}" method="POST" action="{{ route('settings.profile.zone', $zone->id) }}" class="mb-4 scroll-mt-6 rounded-xl border border-gray-200 p-4 last:mb-0 dark:border-gray-700">
            @csrf @method('PUT')
            <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                <h6 class="font-semibold text-gray-900 dark:text-white">{{ $zone->name }}</h6>
                <span class="text-sm text-gray-500">{{ trans_choice(':count ПК|:count ПК|:count ПК', $zone->computers_count) }} · {{ __(':price ₸/час', ['price' => number_format((float) $zone->price_per_hour, 0, '.', ' ')]) }}</span>
            </div>
            <label class="mb-3 block">
                <span class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Описание зоны') }}</span>
                <input type="text" name="description" maxlength="500" value="{{ $zone->description }}" placeholder="{{ __('Например: отдельная комната на 5 ПК') }}" class="{{ $input }}">
            </label>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach($deviceFields as $key => $label)
                    <label>
                        <span class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ $label }}</span>
                        <input type="text" name="devices[{{ $key }}]" maxlength="120" value="{{ $zone->devices[$key] ?? '' }}" placeholder="{{ $detected[$zone->id][$key] ?? '' }}" class="{{ $input }}">
                    </label>
                @endforeach
            </div>
            <div class="mt-3 flex justify-end">
                <button type="submit" class="brand-button rounded-lg px-4 py-2 text-sm">{{ __('Сохранить зону') }}</button>
            </div>
        </form>
    @empty
        <p class="text-sm text-gray-500">{{ __('Зон пока нет: создайте их на карте клуба.') }}</p>
    @endforelse
</x-settings.card>
@endsection
