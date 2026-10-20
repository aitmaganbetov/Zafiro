<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Services\AgentUpdate;
use App\Services\PlatformLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * The PC agent release clubs are told about: clubs whose PCs run an older version see a
 * notice in the panel (owner, admin, manager) and on the PC list. With auto-update on, the
 * agent service of a free PC installs the release from GitHub, checked against its .sha256.
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
            'auto_update' => ['nullable', 'boolean'],
        ], [
            'latest_version.regex' => __('Версия — три числа через точку, например 1.2.0.'),
            'download_url.url' => __('Ссылка на скачивание должна начинаться с https://.'),
        ]);

        $autoUpdate = $request->boolean('auto_update');
        $installerUrl = $autoUpdate ? AgentUpdate::installerUrl($data['latest_version']) : null;
        $sha256 = $installerUrl !== null ? $this->installerChecksum($installerUrl) : null;
        $data['auto_update'] = $autoUpdate;

        DB::transaction(function () use ($data, $request, $installerUrl, $sha256): void {
            PlatformSetting::write(PlatformSetting::AGENT_RELEASE, [
                'latest_version' => $data['latest_version'],
                'released_at' => $data['released_at'],
                'download_url' => $data['download_url'] ?? null,
                'auto_update' => $data['auto_update'],
                'installer_url' => $installerUrl,
                'installer_sha256' => $sha256,
            ], $request->user());
            $this->platformLog->record('superadmin.agent_release_set', __('Объявлена версия агента :version', ['version' => $data['latest_version']]), $request->user(), null, null, $data);
        });

        return back()->with('status', __('Версия агента :version объявлена: клубы со старыми агентами получат уведомление.', ['version' => $data['latest_version']]));
    }

    /**
     * The checksum CI publishes beside the installer ("<sha256>  <file name>"). Without it the
     * agents would have nothing to check the download against, so auto-update is not turned on.
     */
    private function installerChecksum(string $installerUrl): string
    {
        try {
            $response = Http::timeout(15)->get($installerUrl.'.sha256');
        } catch (ConnectionException) {
            $response = null;
        }

        if ($response?->successful() !== true || ! preg_match('/^([0-9a-fA-F]{64})\b/', trim($response->body()), $match)) {
            throw ValidationException::withMessages([
                'auto_update' => __('Не удалось получить контрольную сумму установщика :url. Проверьте, что сборка агента этой версии опубликована на GitHub.', ['url' => $installerUrl]),
            ]);
        }

        return strtolower($match[1]);
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
