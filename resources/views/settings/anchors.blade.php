{{-- Подразделы открытой страницы настроек ($settingsAnchors: id => название); текущий отмечает скрипт в layout. --}}
@if (! empty($settingsAnchors))
<nav class="mb-1 ml-7 space-y-0.5 border-l border-gray-200 pl-3 dark:border-gray-700" aria-label="{{ __('Разделы страницы') }}" data-settings-anchors>
    @foreach ($settingsAnchors as $id => $label)
        <a href="#{{ $id }}" class="block rounded-lg px-2 py-1.5 text-sm text-gray-600 transition hover:bg-gray-100 hover:text-gray-900 aria-[current=true]:font-semibold aria-[current=true]:text-gray-900 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-white dark:aria-[current=true]:text-white">{{ $label }}</a>
    @endforeach
</nav>
@endif
