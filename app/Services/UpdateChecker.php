<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Checks the project's GitHub releases for a newer version than the one running.
 */
class UpdateChecker
{
    public const LATEST_RELEASE = 'updates.latest_release';

    public const CHECKED_AT = 'updates.checked_at';

    public const LAST_SEEN_VERSION = 'updates.last_seen_version';

    public function __construct(private readonly AppSettings $settings) {}

    public function currentVersion(): string
    {
        return ltrim((string) config('prism.version'), 'v');
    }

    /**
     * Fetch the latest release from GitHub and remember it.
     *
     * @return array{version: string, name: string, url: string, download_url: string, notes: string, published_at: ?string}|null
     */
    public function check(): ?array
    {
        try {
            $response = Http::acceptJson()
                ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
                ->connectTimeout(5)
                ->timeout(15)
                ->get('https://api.github.com/repos/'.config('prism.repository').'/releases/latest');
        } catch (ConnectionException) {
            return $this->latestRelease();
        }

        $this->settings->set(self::CHECKED_AT, now()->toIso8601String());

        if ($response->notFound()) {
            $this->settings->set(self::LATEST_RELEASE, null);

            return null;
        }

        $release = $response->throw()->json();
        $latest = [
            'version' => ltrim($release['tag_name'], 'v'),
            'name' => $release['name'] ?: $release['tag_name'],
            'url' => $release['html_url'],
            'download_url' => $this->downloadUrlFor($release['assets'] ?? []) ?? $release['html_url'],
            'notes' => (string) ($release['body'] ?? ''),
            'published_at' => $release['published_at'] ?? null,
        ];

        $this->settings->set(self::LATEST_RELEASE, $latest);

        return $latest;
    }

    /**
     * The release found by the last check, if any.
     *
     * @return array{version: string, name: string, url: string, download_url: string, notes: string, published_at: ?string}|null
     */
    public function latestRelease(): ?array
    {
        return $this->settings->get(self::LATEST_RELEASE);
    }

    public function updateIsAvailable(): bool
    {
        $latest = $this->latestRelease();

        return $latest !== null && version_compare($latest['version'], $this->currentVersion(), '>');
    }

    /**
     * The version whose "What's new" notes the user hasn't seen yet. A fresh install has nothing to announce.
     */
    public function unseenVersion(): ?string
    {
        $current = $this->currentVersion();
        $lastSeen = $this->settings->get(self::LAST_SEEN_VERSION);

        if ($lastSeen === null) {
            $this->markVersionSeen();

            return null;
        }

        return version_compare($current, (string) $lastSeen, '>') ? $current : null;
    }

    public function markVersionSeen(): void
    {
        $this->settings->set(self::LAST_SEEN_VERSION, $this->currentVersion());
    }

    /**
     * Pick the installer for this computer from the release assets.
     *
     * @param  list<array{name: string, browser_download_url: string}>  $assets
     */
    private function downloadUrlFor(array $assets): ?string
    {
        $architecture = in_array(strtolower(php_uname('m')), ['arm64', 'aarch64'], true) ? 'arm64' : 'x64';

        $candidates = collect($assets)->filter(fn (array $asset): bool => match (PHP_OS_FAMILY) {
            'Darwin' => str_ends_with($asset['name'], '.dmg'),
            'Windows' => str_ends_with($asset['name'], '.exe'),
            default => str_ends_with($asset['name'], '.AppImage'),
        });

        $match = $candidates->first(fn (array $asset): bool => str_contains($asset['name'], $architecture)) ?? $candidates->first();

        return $match['browser_download_url'] ?? null;
    }
}
