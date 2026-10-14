<?php

namespace App\Services;

use App\Models\ClubComputer;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * New PC agent release for the current club. The agent does not update itself: the club
 * installs it (on diskless systems, once in the boot image), so the panel only tells them.
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

    private function latestVersion(): ?string
    {
        $version = config('club.agent.latest_version');

        return is_string($version) && preg_match('/^\d+\.\d+\.\d+$/', $version) ? $version : null;
    }

    private function releasedAt(): Carbon
    {
        try {
            return Carbon::parse((string) config('club.agent.released_at'));
        } catch (Throwable) {
            return now()->startOfDay();
        }
    }

    /** Only an https link is shown, so a bad config cannot put a javascript: URL in the panel. */
    private function downloadUrl(): ?string
    {
        $url = config('club.agent.download_url');

        return is_string($url) && str_starts_with($url, 'https://') && filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }
}
