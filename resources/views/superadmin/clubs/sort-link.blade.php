{{-- Заголовок колонки с сортировкой ($key, $label): повторный клик меняет направление. --}}
@php($next = $sort === $key ? ($direction === 'asc' ? 'desc' : 'asc') : $sorts[$key][1])
<a href="{{ request()->fullUrlWithQuery(['sort' => $key, 'direction' => $next, 'page' => null]) }}" class="hover:text-violet-700">{{ $label }}@if($sort === $key) {{ $direction === 'asc' ? '↑' : '↓' }}@endif</a>
