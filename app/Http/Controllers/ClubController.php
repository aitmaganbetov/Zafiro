<?php

namespace App\Http\Controllers;

use App\Models\ClubMembership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ClubController extends Controller
{
    public function switch(Request $request): RedirectResponse
    {
        $validated = $request->validate(['club_id' => ['required', 'integer']]);
        $membership = ClubMembership::query()
            ->withoutGlobalScope('club')
            ->where('user_id', $request->user()->getKey())
            ->where('club_id', $validated['club_id'])
            ->firstOrFail();

        $request->session()->put('current_club_id', $membership->club_id);

        return back();
    }
}
