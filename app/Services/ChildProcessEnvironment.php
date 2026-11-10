<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * The environment for the command line tools PRism starts (claude, git, gh).
 *
 * A child process inherits PRism's own environment unless a variable is removed, and that environment holds what lets
 * a process control the app: NativePHP's API secret and URL, the app key that decrypts PRism's settings, and the token
 * git uses to push. None of the tools need them, and Claude runs commands a pull request can influence.
 */
final class ChildProcessEnvironment
{
    /**
     * Variables whose names start with one of these are never passed on.
     *
     * @var list<string>
     */
    private const REMOVED_PREFIXES = ['NATIVEPHP_', 'NIGHTWATCH_'];

    /**
     * Variables that are only passed on when the caller sets them explicitly.
     *
     * @var list<string>
     */
    private const REMOVED_VARIABLES = ['APP_KEY', 'PRISM_GIT_TOKEN'];

    /**
     * Build the environment for a child process: the variables given, with PRism's own secrets removed from what it
     * would otherwise inherit. Laravel merges this with the inherited environment, where false removes a variable.
     *
     * @param  array<string, string|false>  $environment
     * @return array<string, string|false>
     */
    public static function make(array $environment = []): array
    {
        $removed = collect(self::inheritedNames())
            ->filter(fn (string $name): bool => in_array(strtoupper($name), self::REMOVED_VARIABLES, true)
                || Str::startsWith(strtoupper($name), self::REMOVED_PREFIXES))
            ->mapWithKeys(fn (string $name): array => [$name => false])
            ->all();

        return [...$removed, ...$environment];
    }

    /**
     * The names of the variables a child process would inherit, from the same places Symfony Process reads them.
     *
     * @return list<string>
     */
    private static function inheritedNames(): array
    {
        $names = [...array_keys(getenv()), ...array_keys($_ENV), ...array_keys(array_filter($_SERVER, 'is_string'))];

        return array_values(array_unique(array_filter($names, 'is_string')));
    }
}
