{{-- A link in the landing header menu: $link = [url, icon, title, text, route name or null]. --}}
@php([$url, $icon, $title, $text, $route] = $link)
<a href="{{ $url }}" class="flex gap-3 rounded-xl p-3 hover:bg-gray-100" @if($route !== null && request()->routeIs($route)) aria-current="page" @endif>
    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-[#ff0169]/15 text-[#e6005e]"><i class="fas {{ $icon }}" aria-hidden="true"></i></span>
    <span><span class="block font-semibold text-gray-900">{{ $title }}</span><span class="block text-xs text-gray-500">{{ $text }}</span></span>
</a>
