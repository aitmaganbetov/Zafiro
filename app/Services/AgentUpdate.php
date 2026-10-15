<?php

namespace App\Services;

use App\Models\ClubComputer;
use App\Models\PlatformSetting;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * New PC agent release for the current club. The agent does not update itself: the club
 * installs it (on diskless systems, once in the boot image), so the panel only tells them.
 * The superadmin announces a release in the cabinet; without one, config/club.php (.env) is used.
 */
class AgentUpdate
{
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
     * @return array{latest_version?: mixed, released_at?: mixed, download_url?: mixed}
     */
    public function release(): array
    {
        return PlatformSetting::read(PlatformSetting::AGENT_RELEASE) ?? (array) config('club.agent');
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
