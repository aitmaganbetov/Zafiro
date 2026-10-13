<?php

namespace App\Http\Controllers;

use App\Models\ClubComputer;
use Illuminate\Contracts\View\View;

class ClubComputerListController extends Controller
{
    public function __invoke(): View
    {
        $computers = ClubComputer::query()
            ->with('zone:id,name')
            ->orderBy('club_zone_id')
            ->orderBy('number')
            ->paginate(50);

        return view('club-computers.index', ['computers' => $computers]);
    }
}
