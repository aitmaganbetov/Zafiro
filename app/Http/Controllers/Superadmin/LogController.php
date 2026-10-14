<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Club;
use App\Models\PlatformLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Superadmin actions (platform log) and the activity logs of every club. */
class LogController extends Controller
{
    public function __invoke(Request $request): View
    {
        $filters = $request->validate([
            'tab' => ['nullable', 'in:platform,clubs'],
            'club' => ['nullable', 'integer', 'exists:clubs,id'],
            'event' => ['nullable', 'string', 'max:64'],
        ]);
        $tab = $filters['tab'] ?? 'platform';
        $clubId = $filters['club'] ?? null;
        $event = $filters['event'] ?? null;

        $query = $tab === 'platform'
            ? PlatformLog::query()->with(['user:id,name', 'club:id,name'])
            : ActivityLog::query()->withoutGlobalScope('club')->with(['user:id,name', 'club:id,name']);

        return view('superadmin.logs.index', [
            'tab' => $tab,
            'filters' => $filters,
            'club' => $clubId === null ? null : Club::query()->find($clubId, ['id', 'name']),
            'events' => $tab === 'platform' ? PlatformLog::EVENT_LABELS : ActivityLog::EVENT_LABELS,
            'logs' => $query
                ->when($clubId !== null, fn ($q) => $q->where('club_id', $clubId))
                ->when($event !== null && $event !== '', fn ($q) => $q->where('event', $event))
                ->latest('id')
                ->paginate(50)
                ->withQueryString(),
        ]);
    }
}
