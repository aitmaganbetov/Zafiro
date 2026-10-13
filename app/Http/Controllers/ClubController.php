<?php

namespace App\Http\Controllers;

use App\Models\ClubMembership;
use App\Services\ActivityLogger;
use App\Services\CurrentClub;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ClubController extends Controller
{
    public function switch(Request $request, ActivityLogger $activity, CurrentClub $currentClub): RedirectResponse
    {
        $validated = $request->validate(['club_id' => ['required', 'integer']]);
        $membership = ClubMembership::query()
            ->withoutGlobalScope('club')
            ->where('user_id', $request->user()->getKey())
            ->where('club_id', $validated['club_id'])
            ->firstOrFail();

        $request->session()->put('current_club_id', $membership->club_id);

        if ($membership->club_id !== $currentClub->id()) {
            $activity->record('club.switched', __('Переход в клуб из клуба #:id', ['id' => $currentClub->id()]), $request->user()->getKey(), null, [
                'from_club_id' => $currentClub->id(),
            ], $membership->club_id);
        }

        return back();
    }
}
