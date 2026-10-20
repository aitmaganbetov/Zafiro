<?php

namespace App\Services;

use App\Models\ClubComputer;
use App\Models\PlatformSetting;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * New PC agent release for the current club. The superadmin announces a release in the cabinet
 * (without one, config/club.php (.env) is used) and the panel tells the club. With auto-update
 * on, the agent service of an idle PC installs it itself; diskless systems lose it on restart,
 * so they are offered it only a few times and the club updates the boot image.
 */
class AgentUpdate
{
    /** The installer of a release built by CI (.github/workflows/windows-agent.yml), with its .sha256 beside it. */
    public const INSTALLER_URL = 'https://github.com/aitmaganbetov/Zafiro/releases/download/agent-v{version}/ZafiroAgentSetup-{version}.exe';

    /** Offers of one version to one PC, and the pause between them. */
    public const MAX_OFFERS = 3;

    public const OFFER_INTERVAL_MINUTES = 120;

    public function __construct(private CurrentClub $currentClub) {}

    /**
     * @return array{version: string, released_at: Carbon, download_url: ?string, outdated: int, total: int}|null
     */
    public function status(): ?array
    {
        $latest = $this->latestVersion();

        if ($latest === null) {
            return null;
        }

        $versions = ClubComputer::query()->whereNotNull('agent_version')->pluck('agent_version');
        $outdated = $versions->filter(fn (string $version): bool => $this->isOutdated($version, $latest))->count();

        if ($outdated === 0) {
            return null;
        }

        return [
            'version' => $latest,
            'released_at' => $this->releasedAt(),
            'download_url' => $this->downloadUrl(),
            'outdated' => $outdated,
            'total' => $versions->count(),
        ];
    }

    public function isOutdated(?string $version, ?string $latest = null): bool
    {
        $latest ??= $this->latestVersion();

        if ($latest === null || $version === null) {
            return false;
        }

        // An unreadable version (e.g. a hand-made build) is treated as old.
        return ! preg_match('/^\d+\.\d+\.\d+/', $version, $match) || version_compare($match[0], $latest, '<');
    }

    public function latestVersion(): ?string
    {
        $version = $this->release()['latest_version'] ?? null;

        return is_string($version) && preg_match('/^\d+\.\d+\.\d+$/', $version) ? $version : null;
    }

    /**
     * The announced release: from the cabinet, otherwise from config.
     *
     * @return array{latest_version?: mixed, released_at?: mixed, download_url?: mixed, auto_update?: mixed, installer_url?: mixed, installer_sha256?: mixed}
     */
    public function release(): array
    {
        return PlatformSetting::read(PlatformSetting::AGENT_RELEASE) ?? (array) config('club.agent');
    }

    public static function installerUrl(string $version): string
    {
        return str_replace('{version}', $version, self::INSTALLER_URL);
    }

    /**
     * The installer the agent service may install itself: the release has auto-update on
     * and a known checksum.
     *
     * @return array{version: string, url: string, sha256: string}|null
     */
    public function autoInstaller(): ?array
    {
        $release = $this->release();
        $version = $this->latestVersion();
        $url = $release['installer_url'] ?? null;
        $sha256 = $release['installer_sha256'] ?? null;

        if (($release['auto_update'] ?? false) !== true || $version === null
            || ! is_string($url) || ! str_starts_with($url, 'https://')
            || ! is_string($sha256) || ! preg_match('/^[0-9a-f]{64}$/', $sha256)) {
            return null;
        }

        return ['version' => $version, 'url' => $url, 'sha256' => $sha256];
    }

    /** Club settings → Agent on PC; on by default. */
    public function clubAllowsAutoUpdate(): bool
    {
        return filter_var($this->currentClub->settings()['club']['agent_auto_update'] ?? true, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * The update offered to the agent service of this PC, or null. Only a PC nobody uses gets it
     * (installing restarts the agent), and one version is offered at most MAX_OFFERS times,
     * OFFER_INTERVAL_MINUTES apart: a diskless PC forgets the update on every restart.
     *
     * @return array{version: string, url: string, sha256: string}|null
     */
    public function offerFor(ClubComputer $computer): ?array
    {
        $installer = $this->autoInstaller();

        if ($installer === null || ! $this->clubAllowsAutoUpdate()
            || ! $this->isOutdated($computer->agent_version, $installer['version'])
            || $computer->hasPaidSession() || in_array($computer->agent_state, ['idle', 'in_session'], true)) {
            return null;
        }

        $sameVersion = $computer->agent_update_version === $installer['version'];
        $offers = $sameVersion ? (int) $computer->agent_update_offers : 0;

        if ($offers >= self::MAX_OFFERS
            || ($sameVersion && $computer->agent_update_offered_at?->gt(now()->subMinutes(self::OFFER_INTERVAL_MINUTES)))) {
            return null;
        }

        $computer->forceFill([
            'agent_update_version' => $installer['version'],
            'agent_update_offers' => $offers + 1,
            'agent_update_offered_at' => now(),
        ])->save();

        return $installer;
    }

    private function releasedAt(): Carbon
    {
        try {
            return Carbon::parse((string) ($this->release()['released_at'] ?? ''));
        } catch (Throwable) {
            return now()->startOfDay();
        }
    }

    /** Only an https link is shown, so a bad config cannot put a javascript: URL in the panel. */
    public function downloadUrl(): ?string
    {
        $url = $this->release()['download_url'] ?? null;

        return is_string($url) && str_starts_with($url, 'https://') && filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }
}
