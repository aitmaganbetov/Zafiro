<?php

namespace App\Http\Controllers;

use App\Models\SaasPlan;
use App\Services\AgentUpdate;
use Illuminate\View\View;

/** The public pages of zafiro.kz: the home page and the "About the product" pages. */
class LandingController extends Controller
{
    /** The latest installer when the superadmin has not set a download link. */
    public const AGENT_DOWNLOAD = 'https://github.com/aitmaganbetov/Zafiro/releases/latest/download/ZafiroAgentSetup.exe';

    public function __construct(private AgentUpdate $agent) {}

    public function __invoke(): View
    {
        return view('welcome', $this->data());
    }

    public function features(): View
    {
        return view('product.features', $this->data());
    }

    public function agent(): View
    {
        return view('product.agent', $this->data());
    }

    public function guestApp(): View
    {
        return view('product.guest-app', $this->data());
    }

    public function staffApp(): View
    {
        return view('product.staff-app', $this->data());
    }

    /** Plans open for subscription, as the superadmin set them up (free and "on request" ones first). */
    public function pricing(): View
    {
        return view('pricing', $this->data() + [
            'plans' => SaasPlan::query()->where('is_active', true)
                ->orderByRaw('price_monthly IS NOT NULL')->orderBy('price_monthly')->orderBy('id')
                ->get(['id', 'name', 'price_monthly', 'trial_days', 'features']),
        ]);
    }

    /** @return array{trialDays: int, downloadUrl: string} */
    private function data(): array
    {
        return [
            'trialDays' => (int) (SaasPlan::query()->where('is_active', true)->max('trial_days') ?: 14),
            'downloadUrl' => $this->agent->downloadUrl() ?? self::AGENT_DOWNLOAD,
        ];
    }
}
