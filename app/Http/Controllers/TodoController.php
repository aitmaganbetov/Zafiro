<?php

namespace App\Http\Controllers;

use App\Models\TodoTask;
use App\Services\ActivityLogger;
use App\Services\CurrentClub;
use App\Support\ClubPermissions;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * To Do list of the club: the admin / manager writes tasks for a day; cashiers and operators
 * on shift see one shared list. A task marked done by one is done for everybody.
 * Undone tasks of earlier days stay on today's list as overdue.
 */
class TodoController extends Controller
{
    public function __construct(private CurrentClub $currentClub, private ActivityLogger $activity) {}

    public function index(Request $request): View
    {
        $day = $this->day($request);

        return view('todos.index', [
            'day' => $day,
            'isToday' => $day->isSameDay($this->today()),
            'tasks' => $this->tasks($day),
            'canManage' => ClubPermissions::allows($this->currentClub->membership(), 'todos.create'),
            'canDelete' => ClubPermissions::allows($this->currentClub->membership(), 'todos.delete'),
        ]);
    }

    /** Who did what, for the open page to stay in sync without reloading. */
    public function state(Request $request): JsonResponse
    {
        return response()->json(['tasks' => $this->tasks($this->day($request))->map(fn (TodoTask $task): array => [
            'id' => $task->id,
            'done' => $task->done_at !== null,
            'done_by' => $task->doneBy?->name,
            'done_at' => $task->done_at?->format('H:i'),
        ])->values()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'due_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$this->today()->format('Y-m-d')],
        ], ['due_on.after_or_equal' => __('Задачу нельзя поставить на прошедший день.')]);

        DB::transaction(function () use ($data, $request): void {
            $task = new TodoTask($data);
            $task->forceFill(['created_by' => $request->user()->getKey()])->save();
            $this->activity->record('todo.created', __('Задача на :date: :title', ['date' => Carbon::parse($task->due_on)->format('d.m.Y'), 'title' => $task->title]), $request->user()->getKey(), $task);
        });

        return redirect()->route('todos.index', ['date' => $data['due_on']])->with('status', __('Задача добавлена.'));
    }

    public function toggle(Request $request, TodoTask $task): RedirectResponse|JsonResponse
    {
        $done = $request->boolean('done');
        DB::transaction(function () use ($task, $done, $request): void {
            $task = TodoTask::query()->lockForUpdate()->findOrFail($task->getKey());
            if (($task->done_at !== null) === $done) {
                return;
            }
            $task->forceFill(['done_at' => $done ? now() : null, 'done_by' => $done ? $request->user()->getKey() : null])->save();
            $description = $done
                ? __('Выполнена задача: :title', ['title' => $task->title])
                : __('Задача снова не выполнена: :title', ['title' => $task->title]);
            $this->activity->record($done ? 'todo.done' : 'todo.reopened', $description, $request->user()->getKey(), $task);
        });

        return $request->expectsJson() ? response()->json(['ok' => true]) : back();
    }

    public function destroy(Request $request, TodoTask $task): RedirectResponse
    {
        DB::transaction(function () use ($task, $request): void {
            $task->delete();
            $this->activity->record('todo.deleted', __('Удалена задача: :title', ['title' => $task->title]), $request->user()->getKey());
        });

        return back()->with('status', __('Задача удалена.'));
    }

    /** @return Collection<int, TodoTask> the day's tasks; on today, overdue undone ones first */
    private function tasks(Carbon $day): Collection
    {
        $isToday = $day->isSameDay($this->today());

        return TodoTask::query()
            ->with('doneBy:id,name')
            ->where(fn ($query) => $query
                ->where('due_on', $day->format('Y-m-d'))
                ->when($isToday, fn ($query) => $query->orWhere(fn ($overdue) => $overdue->where('due_on', '<', $day->format('Y-m-d'))->whereNull('done_at'))))
            ->orderBy('due_on')->orderBy('id')
            ->limit(300)
            ->get();
    }

    private function day(Request $request): Carbon
    {
        $date = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']])['date'] ?? null;

        return $date === null ? $this->today() : Carbon::createFromFormat('Y-m-d', $date, $this->currentClub->timezone())->startOfDay();
    }

    private function today(): Carbon
    {
        return Carbon::now($this->currentClub->timezone())->startOfDay();
    }
}
