<?php

namespace App\Http\Controllers;

use App\Models\ClubSubscription;
use App\Services\CurrentClub;
use Illuminate\View\View;

class BillingController extends Controller
{
    public function __construct(private CurrentClub $currentClub) {}

    public function show(): View
    {
        $subscription = ClubSubscription::query()
            ->with('plan')
            ->where('club_id', $this->currentClub->id())
            ->first();

        return view('billing.index', ['subscription' => $subscription]);
    }
}
