<?php

namespace App\Services;

use Symfony\Component\Process\ExecutableFinder;

/**
 * Finds command line tools such as `claude` and `gh`.
 *
 * Desktop apps launched from Finder or the Start menu get a minimal PATH, so the
 * usual install locations are searched as well as the inherited PATH.
 */
class CommandLocator
{
    public function __construct(private readonly ExecutableFinder $finder = new ExecutableFinder) {}

    /**
     * Find a command, preferring an explicit path configured by the user.
     */
    public function find(string $command, ?string $preferredPath = null): ?string
    {
        if (filled($preferredPath) && is_file($preferredPath) && is_executable($preferredPath)) {
            return $preferredPath;
        }

        return $this->finder->find($command, null, $this->extraDirectories());
    }

    /**
     * Build a PATH that lets child processes (and the tools they run) find the same commands.
     *
     * @param  list<string|null>  $commandPaths
     */
    public function searchPath(array $commandPaths = []): string
    {
        $directories = collect($commandPaths)
            ->filter()
            ->map(fn (string $path): string => dirname($path))
            ->merge(explode(PATH_SEPARATOR, (string) getenv('PATH')))
            ->merge($this->extraDirectories())
            ->filter(fn (string $directory): bool => $directory !== '' && is_dir($directory))
            ->unique()
            ->values();

        return $directories->implode(PATH_SEPARATOR);
    }

    /**
     * @return list<string>
     */
    public function extraDirectories(): array
    {
        $home = (string) (getenv('HOME') ?: getenv('USERPROFILE'));

        if (PHP_OS_FAMILY === 'Windows') {
            return array_values(array_filter([
                $home ? $home.'\\.local\\bin' : null,
                getenv('APPDATA') ? getenv('APPDATA').'\\npm' : null,
                getenv('LOCALAPPDATA') ? getenv('LOCALAPPDATA').'\\Programs\\claude' : null,
                getenv('ProgramFiles') ? getenv('ProgramFiles').'\\GitHub CLI' : null,
                getenv('ProgramFiles') ? getenv('ProgramFiles').'\\Git\\cmd' : null,
            ]));
        }

        return array_values(array_filter([
            $home ? $home.'/.local/bin' : null,
            $home ? $home.'/.claude/local' : null,
            $home ? $home.'/.npm-global/bin' : null,
            $home ? $home.'/.bun/bin' : null,
            $home ? $home.'/.volta/bin' : null,
            '/opt/homebrew/bin',
            '/usr/local/bin',
            '/usr/bin',
            '/bin',
        ]));
    }
}
