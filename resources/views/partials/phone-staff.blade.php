{{-- A stylised staff app phone with the club map. --}}
@php
    $tiles = ['free', 'busy', 'busy', 'guest', 'free', 'busy', 'staff', 'busy', 'free', 'busy', 'busy', 'booked'];
    $tileColors = ['free' => 'bg-emerald-50 text-emerald-700 ring-emerald-300', 'busy' => 'bg-[#ff0169]/10 text-[#d90058] ring-[#ff0169]/40', 'guest' => 'bg-sky-50 text-sky-700 ring-sky-300', 'staff' => 'bg-red-50 text-red-700 ring-red-300', 'booked' => 'bg-amber-50 text-amber-700 ring-amber-300'];
@endphp
<div class="relative rounded-[2.5rem] border-[10px] border-gray-900 bg-white p-4 shadow-2xl">
    <div class="mx-auto mb-4 h-1.5 w-16 rounded-full bg-gray-200"></div>
    <div class="flex items-center justify-between">
        <p class="font-bold">{{ __('Карта клуба') }}</p>
        <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] text-gray-500">{{ __('Смена открыта') }}</span>
    </div>
    <p class="mt-3 text-[10px] font-semibold uppercase tracking-wider text-gray-400">Bootcamp</p>
    <div class="mt-2 grid grid-cols-4 gap-2">
        @foreach($tiles as $state)
            <span class="flex h-11 items-center justify-center rounded-xl text-sm font-bold ring-1 {{ $tileColors[$state] }}">{{ $loop->iteration }}</span>
        @endforeach
    </div>
    <div class="mt-4 grid grid-cols-3 gap-2 text-center text-[10px] font-semibold">
        <span class="flex flex-col items-center gap-1 rounded-xl bg-gray-100 py-2"><i class="fas fa-rotate-right text-sm text-gray-600"></i>{{ __('Перезагрузить') }}</span>
        <span class="flex flex-col items-center gap-1 rounded-xl bg-gray-100 py-2"><i class="fas fa-power-off text-sm text-gray-600"></i>{{ __('Выключить') }}</span>
        <span class="flex flex-col items-center gap-1 rounded-xl bg-[#ff0169] py-2 text-white"><i class="fas fa-stopwatch text-sm"></i>{{ __('Время гостю') }}</span>
    </div>
</div>
