<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\Language;
use App\Services\ActivityLogger;
use App\Services\CurrentClub;
use App\Services\LocaleResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Each club picks the interface language it uses. The languages themselves and their
 * translations are managed by the platform superadmin (Superadmin\LanguageController).
 */
class LanguageController extends Controller
{
    public function __construct(
        private CurrentClub $currentClub,
        private LocaleResolver $locales,
        private ActivityLogger $activity,
    ) {}

    public function select(Request $request): RedirectResponse
    {
        $data = $request->validate(['locale' => ['required', 'string', Rule::in($this->locales->enabledCodes())]]);
        $club = $this->currentClub->get();
        $from = $this->locales->forClub($club);

        DB::transaction(function () use ($club, $data, $from, $request): void {
            $club->forceFill(['locale' => $data['locale']])->save();
            $this->locales->apply($data['locale']);
            $this->activity->record('club.language_changed', __('Язык клуба изменён: :from → :to', ['from' => $from, 'to' => $data['locale']]), $request->user()->getKey(), $club, [
                'from' => $from,
                'to' => $data['locale'],
            ]);
        });

        return back()->with('status', __('Язык интерфейса изменён.'));
    }
}
