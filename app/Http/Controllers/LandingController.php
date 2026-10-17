<?php

namespace App\Http\Controllers;

use App\Models\SaasPlan;
use App\Services\AgentUpdate;
use Illuminate\View\View;

/** The public home page of zafiro.kz. */
class LandingController extends Controller
{
    /** The latest installer when the superadmin has not set a download link. */
    public const AGENT_DOWNLOAD = 'https://github.com/aitmaganbetov/Zafiro/releases/latest/download/ZafiroAgentSetup.exe';

    public function __invoke(AgentUpdate $agent): View
    {
        return view('welcome', [
            'trialDays' => (int) (SaasPlan::query()->where('is_active', true)->max('trial_days') ?: 14),
            'downloadUrl' => $agent->downloadUrl() ?? self::AGENT_DOWNLOAD,
        ]);
    }
}
