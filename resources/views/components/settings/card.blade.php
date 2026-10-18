{{-- Карточка подраздела настроек; id карточки — якорь в левом меню. --}}
<section {{ $attributes->class('scroll-mt-6 bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 p-6 animate-slide shadow-sm animation-delay-100') }}>
    {{ $slot }}
</section>
