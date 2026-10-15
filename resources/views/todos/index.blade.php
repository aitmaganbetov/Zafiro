@extends('layouts.admin')

@section('page-title', __('To Do List'))

@section('content')
@php
    $field = 'rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-white';
@endphp
<div class="p-6 lg:p-8">
    <header class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h3 class="mb-2 text-2xl font-bold text-gray-900 dark:text-white">{{ __('To Do List') }}</h3>
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('Один список на смену: если задачу отметил один сотрудник, у остальных она тоже выполнена.') }}</p>
        </div>
        <form method="GET" action="{{ route('todos.index') }}" class="flex items-center gap-2">
            <a href="{{ route('todos.index', ['date' => $day->copy()->subDay()->format('Y-m-d')]) }}" class="rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-700 dark:border-gray-700 dark:text-gray-300" aria-label="{{ __('Предыдущий день') }}"><i class="fas fa-chevron-left"></i></a>
            <input type="date" name="date" value="{{ $day->format('Y-m-d') }}" aria-label="{{ __('День') }}" class="{{ $field }}" onchange="this.form.submit()">
            <a href="{{ route('todos.index', ['date' => $day->copy()->addDay()->format('Y-m-d')]) }}" class="rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-700 dark:border-gray-700 dark:text-gray-300" aria-label="{{ __('Следующий день') }}"><i class="fas fa-chevron-right"></i></a>
        </form>
    </header>

    @if(session('status'))<div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300" role="alert">{{ $errors->first() }}</div>@endif

    @if($canManage)
        <form method="POST" action="{{ route('todos.store') }}" class="mb-6 flex flex-wrap items-end gap-3 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            @csrf
            <label class="min-w-0 flex-1 basis-72"><span class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('Что нужно сделать') }}</span><input name="title" maxlength="200" required value="{{ old('title') }}" class="{{ $field }} w-full"></label>
            <label><span class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('На какой день') }}</span><input type="date" name="due_on" required value="{{ old('due_on', $isToday || $day->isPast() ? now(app(\App\Services\CurrentClub::class)->timezone())->format('Y-m-d') : $day->format('Y-m-d')) }}" class="{{ $field }}"></label>
            <button type="submit" class="brand-button rounded-lg px-4 py-2 text-sm"><i class="fas fa-plus"></i>{{ __('Добавить задачу') }}</button>
        </form>
    @endif

    <ul class="divide-y divide-gray-100 overflow-hidden rounded-xl border border-gray-200 bg-white dark:divide-gray-800 dark:border-gray-800 dark:bg-gray-900" data-todo-list data-state-url="{{ route('todos.state', ['date' => $day->format('Y-m-d')]) }}" data-ids="{{ $tasks->pluck('id')->implode(',') }}">
        @forelse($tasks as $task)
            @php($overdue = $task->due_on < $day->format('Y-m-d'))
            <li class="flex items-center gap-3 px-4 py-3" data-task="{{ $task->id }}">
                <form method="POST" action="{{ route('todos.toggle', $task) }}" class="flex flex-1 items-center gap-3">
                    @csrf
                    <input type="hidden" name="done" value="{{ $task->done_at ? 0 : 1 }}">
                    <button type="submit" class="grid h-6 w-6 flex-none place-items-center rounded-md border {{ $task->done_at ? 'border-green-600 bg-green-600 text-white' : 'border-gray-300 dark:border-gray-600' }}" aria-pressed="{{ $task->done_at ? 'true' : 'false' }}" aria-label="{{ $task->done_at ? __('Отметить как невыполненную') : __('Отметить как выполненную') }}" data-toggle>@if($task->done_at)<i class="fas fa-check text-xs"></i>@endif</button>
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm {{ $task->done_at ? 'text-gray-400 line-through' : 'text-gray-900 dark:text-white' }}" data-title>{{ $task->title }}</span>
                        <span class="block text-xs text-gray-500 dark:text-gray-400" data-meta>
                            @if($overdue)<span class="font-medium text-red-600">{{ __('Просрочено с :date', ['date' => \Illuminate\Support\Carbon::parse($task->due_on)->format('d.m')]) }}</span>@endif
                            @if($task->done_at){{ __('Выполнил(а) :name в :time', ['name' => $task->doneBy?->name ?? '—', 'time' => $task->done_at->format('H:i')]) }}@endif
                        </span>
                    </span>
                </form>
                @if($canManage)
                    <form method="POST" action="{{ route('todos.destroy', $task) }}" onsubmit="return confirm(@js(__('Удалить задачу?')))">@csrf @method('DELETE')<button type="submit" class="text-gray-400 hover:text-red-600" aria-label="{{ __('Удалить задачу') }}"><i class="fas fa-trash"></i></button></form>
                @endif
            </li>
        @empty
            <li class="px-4 py-10 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('На этот день задач нет.') }}</li>
        @endforelse
    </ul>
</div>
@endsection

@push('scripts')
<script>
// Another staff member marked a task: the page follows without reloading (every 15 s).
(() => {
    const list = document.querySelector('[data-todo-list]');
    if (!list) return;
    const known = list.dataset.ids;
    setInterval(async () => {
        if (document.hidden) return;
        try {
            const response = await fetch(list.dataset.stateUrl, { headers: { Accept: 'application/json' } });
            if (!response.ok) return;
            const tasks = (await response.json()).tasks;
            const ids = tasks.map((task) => task.id).join(',');
            const changed = ids !== known || tasks.some((task) => {
                const button = list.querySelector(`[data-task="${task.id}"] [data-toggle]`);
                return !button || (button.getAttribute('aria-pressed') === 'true') !== task.done;
            });
            if (changed) location.reload();
        } catch {
            // Offline: try again on the next tick.
        }
    }, 15000);
})();
</script>
@endpush
