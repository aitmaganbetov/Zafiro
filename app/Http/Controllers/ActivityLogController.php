<?php

namespace App\Http\Controllers;

use App\Http\Requests\FilterActivityLogsRequest;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class ActivityLogController extends Controller
{
    public function index(FilterActivityLogsRequest $request): View
    {
        $filters = $request->validated();
        $query = ActivityLog::query()->with('user:id,name,phone')->latest();

        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }

        if (! empty($filters['to'])) {
            $query->where('created_at', '<', Carbon::parse($filters['to'])->addDay()->startOfDay());
        }

        if (! empty($filters['event'])) {
            $query->where('event', $filters['event']);
        }

        if (! empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function (Builder $query) use ($search): void {
                $query->where('description', 'like', '%'.$search.'%')
                    ->orWhereHas('user', fn (Builder $userQuery) => $userQuery
                        ->where('name', 'like', '%'.$search.'%')
                        ->orWhere('phone', 'like', '%'.$search.'%'));
            });
        }

        return view('activity-logs.index', [
            'logs' => $query->paginate(50)->withQueryString(),
            'filters' => $filters,
            'eventLabels' => array_map(__(...), ActivityLog::EVENT_LABELS),
            'users' => User::query()
                ->whereIn('id', ActivityLog::query()->select('user_id')->whereNotNull('user_id'))
                ->orderBy('name')->get(['id', 'name', 'phone']),
        ]);
    }
}
