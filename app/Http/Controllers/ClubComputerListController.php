<?php

namespace App\Http\Controllers;

use App\Models\ClubComputer;
use App\Services\AgentUpdate;
use Illuminate\Contracts\View\View;

class ClubComputerListController extends Controller
{
    public function __invoke(AgentUpdate $agentUpdate): View
    {
        $computers = ClubComputer::query()
            ->with('zone:id,name')
            ->orderBy('club_zone_id')
            ->orderBy('number')
            ->paginate(50);

        return view('club-computers.index', ['computers' => $computers, 'agentUpdate' => $agentUpdate->status(), 'agentVersions' => $agentUpdate]);
    }
}
