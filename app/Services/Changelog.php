<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Reads and updates CHANGELOG.md, which follows the "Keep a Changelog" format:
 * changes waiting for a release live under "## [Unreleased]", grouped into
 * "### Added", "### Changed", "### Fixed" and so on.
 *
 * @see https://keepachangelog.com/en/1.1.0/
 */
class Changelog
{
    public const UNRELEASED = 'Unreleased';

    /**
     * Section headings that make the next release a minor version, unless something is breaking.
     *
     * @var list<string>
     */
    private const MINOR_SECTIONS = ['Added', 'Changed', 'Deprecated'];

    public function __construct(private readonly string $path) {}

    public function markdown(): string
    {
        return is_file($this->path) ? (string) file_get_contents($this->path) : '';
    }

    /**
     * Every version in the changelog, newest first, including "Unreleased".
     *
     * @return list<array{version: string, date: ?string, notes: string}>
     */
    public function versions(): array
    {
        preg_match_all('/^## \[(?<version>[^\]]+)\](?: - (?<date>\d{4}-\d{2}-\d{2}))?[^\n]*\n(?<notes>.*?)(?=^## \[|\z)/ms', $this->markdown(), $matches, PREG_SET_ORDER);

        return array_map(fn (array $match): array => [
            'version' => $match['version'],
            'date' => $match['date'] !== '' ? $match['date'] : null,
            'notes' => trim($this->withoutLinkReferences($match['notes'])),
        ], $matches);
    }

    /**
     * Released versions only, newest first.
     *
     * @return list<array{version: string, date: ?string, notes: string}>
     */
    public function releases(): array
    {
        return array_values(array_filter($this->versions(), fn (array $version): bool => $version['version'] !== self::UNRELEASED));
    }

    public function notesFor(string $version): ?string
    {
        $version = ltrim($version, 'v');

        foreach ($this->versions() as $entry) {
            if ($entry['version'] === $version) {
                return $entry['notes'];
            }
        }

        return null;
    }

    public function latestVersion(): ?string
    {
        return $this->releases()[0]['version'] ?? null;
    }

    /**
     * Work out the next version from what is waiting under "Unreleased", or null when nothing is.
     *
     * Breaking changes or removals bump the major version, additions and changes the minor
     * version, and anything else (fixes, security) the patch version.
     */
    public function nextVersion(): ?string
    {
        $unreleased = $this->notesFor(self::UNRELEASED);

        if ($unreleased === null || ! preg_match('/^\s*[-*] \S/m', $unreleased)) {
            return null;
        }

        [$major, $minor, $patch] = array_map('intval', explode('.', $this->latestVersion() ?? '0.0.0') + [0, 0, 0]);

        if (preg_match('/BREAKING|^### Removed/mi', $unreleased)) {
            return ($major + 1).'.0.0';
        }

        if (preg_match('/^### ('.implode('|', self::MINOR_SECTIONS).')\b/mi', $unreleased)) {
            return "{$major}.".($minor + 1).'.0';
        }

        return "{$major}.{$minor}.".($patch + 1);
    }

    /**
     * Move everything under "Unreleased" into a new version section and start a fresh "Unreleased".
     */
    public function release(string $version, string $date): string
    {
        $markdown = $this->markdown();

        $released = preg_replace(
            '/^## \['.self::UNRELEASED.'\][^\n]*\n/m',
            '## ['.self::UNRELEASED."]\n\n## [{$version}] - {$date}\n",
            $markdown,
            1,
        );

        return Str::finish((string) $released, "\n");
    }

    public function write(string $markdown): void
    {
        file_put_contents($this->path, $markdown);
    }

    private function withoutLinkReferences(string $notes): string
    {
        return (string) preg_replace('/^\[[^\]]+\]: \S+\s*$/m', '', $notes);
    }
}
