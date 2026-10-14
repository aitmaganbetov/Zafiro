@extends('layouts.superadmin')
@section('page-title', __('Журнал'))

@section('content')
<nav class="mb-4 flex gap-2" aria-label="{{ __('Журналы') }}">
    <a href="{{ route('superadmin.logs.index', array_filter(['club' => $filters['club'] ?? null])) }}" class="rounded-lg px-4 py-2 text-sm {{ $tab === 'platform' ? 'bg-violet-700 text-white' : 'border border-gray-300' }}" @if($tab === 'platform') aria-current="page" @endif>{{ __('Действия суперадминов') }}</a>
    <a href="{{ route('superadmin.logs.index', array_filter(['tab' => 'clubs', 'club' => $filters['club'] ?? null])) }}" class="rounded-lg px-4 py-2 text-sm {{ $tab === 'clubs' ? 'bg-violet-700 text-white' : 'border border-gray-300' }}" @if($tab === 'clubs') aria-current="page" @endif>{{ __('Журналы клубов') }}</a>
</nav>

<form method="GET" class="mb-4 flex flex-wrap items-end gap-3 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
    <input type="hidden" name="tab" value="{{ $tab }}">
    @if($club)<input type="hidden" name="club" value="{{ $club->id }}">@endif
    <label><span class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Событие') }}</span>
        <select name="event" class="rounded-lg border border-gray-300 bg-gray-50 px-3 py-2">
            <option value="">{{ __('Все') }}</option>
            @foreach($events as $event => $label)<option value="{{ $event }}" @selected(($filters['event'] ?? '') === $event)>{{ __($label) }}</option>@endforeach
        </select>
    </label>
    <button type="submit" class="rounded-lg border border-gray-300 px-4 py-2 text-sm">{{ __('Показать') }}</button>
    @if($club)<a href="{{ route('superadmin.logs.index', ['tab' => $tab]) }}" class="text-sm text-violet-700 hover:underline">{{ __('Клуб «:club» · показать все клубы', ['club' => $club->name]) }}</a>@endif
</form>

<div class="rounded-xl border border-gray-200 bg-white p-2 dark:border-gray-700 dark:bg-gray-900">
    @include('superadmin.logs.rows', ['logs' => $logs, 'platform' => $tab === 'platform'])
</div>
<div class="mt-4">{{ $logs->links() }}</div>
@endsection
