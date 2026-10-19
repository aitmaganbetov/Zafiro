{{-- Линейный график из App\Support\LineChart: $chart, $title, $unit (подпись значений). --}}
<figure class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
    <figcaption class="mb-2 flex flex-wrap items-baseline justify-between gap-2">
        <span class="font-semibold text-gray-900 dark:text-white">{{ $title }}</span>
        <span class="text-xs text-gray-500">{{ __('Шкала до :max :unit', ['max' => $chart['max'] >= 10 ? (int) round($chart['max']) : round($chart['max'], 1), 'unit' => $unit]) }}</span>
    </figcaption>
    <svg viewBox="0 0 {{ App\Support\LineChart::WIDTH }} {{ App\Support\LineChart::HEIGHT }}" preserveAspectRatio="none" class="h-40 w-full" role="img" aria-label="{{ $title }}">
        @foreach([0.25, 0.5, 0.75] as $grid)
            <line x1="0" x2="{{ App\Support\LineChart::WIDTH }}" y1="{{ App\Support\LineChart::HEIGHT * $grid }}" y2="{{ App\Support\LineChart::HEIGHT * $grid }}" stroke="currentColor" class="text-gray-200 dark:text-gray-800" stroke-width="1" vector-effect="non-scaling-stroke"/>
        @endforeach
        @foreach($chart['lines'] as $line)
            @foreach($line['segments'] as $points)
                <polyline points="{{ $points }}" fill="none" stroke="{{ $line['color'] }}" stroke-width="2" stroke-linejoin="round" vector-effect="non-scaling-stroke"/>
            @endforeach
        @endforeach
    </svg>
    <div class="mt-1 flex justify-between text-xs tabular-nums text-gray-500">
        @if($chart['from'])<span>{{ Carbon\Carbon::createFromTimestamp($chart['from'] * 60)->format('d.m H:i') }}</span><span>{{ Carbon\Carbon::createFromTimestamp($chart['to'] * 60)->format('d.m H:i') }}</span>@endif
    </div>
    @if(count($chart['lines']) > 1)
        <ul class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-600 dark:text-gray-400">
            @foreach($chart['lines'] as $line)<li><span class="mr-1 inline-block h-2 w-2 rounded-full" style="background-color: {{ $line['color'] }}"></span>{{ $line['label'] }}</li>@endforeach
        </ul>
    @endif
</figure>
