<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Services\PlatformLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The PC agent release clubs are told about: clubs whose PCs run an older version see a
 * notice in the panel (owner, admin, manager) and on the PC list. Agents never update themselves.
 */
class AgentReleaseController extends Controller
{
    public function __construct(private PlatformLogger $platformLog) {}

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'latest_version' => ['required', 'string', 'regex:/^\d{1,4}\.\d{1,4}\.\d{1,6}$/'],
            'released_at' => ['required', 'date'],
            'download_url' => ['nullable', 'url:https', 'max:500'],
        ], [
            'latest_version.regex' => __('Версия — три числа через точку, например 1.2.0.'),
            'download_url.url' => __('Ссылка на скачивание должна начинаться с https://.'),
        ]);

        DB::transaction(function () use ($data, $request): void {
            PlatformSetting::write(PlatformSetting::AGENT_RELEASE, [
                'latest_version' => $data['latest_version'],
                'released_at' => $data['released_at'],
                'download_url' => $data['download_url'] ?? null,
            ], $request->user());
            $this->platformLog->record('superadmin.agent_release_set', __('Объявлена версия агента :version', ['version' => $data['latest_version']]), $request->user(), null, null, $data);
        });

        return back()->with('status', __('Версия агента :version объявлена: клубы со старыми агентами получат уведомление.', ['version' => $data['latest_version']]));
    }

    public function destroy(Request $request): RedirectResponse
    {
        DB::transaction(function () use ($request): void {
            PlatformSetting::write(PlatformSetting::AGENT_RELEASE, null, $request->user());
            $this->platformLog->record('superadmin.agent_release_cleared', __('Объявление о версии агента снято'), $request->user());
        });

        return back()->with('status', __('Объявление снято.'));
    }
}
