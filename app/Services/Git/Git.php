<?php

namespace App\Services\Git;

use App\Services\ChildProcessEnvironment;
use App\Services\CommandLocator;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Runs git against the user's local checkouts.
 *
 * Work always happens in separate worktrees under storage, so the user's own checkout
 * (and whatever branch they have checked out) is never touched. Remote operations use
 * HTTPS with the connected GitHub token, supplied through an inline credential helper
 * that reads it from the environment, so the token never appears in command arguments.
 *
 * A pull request controls the files in its worktree, so git never runs hooks (husky and
 * the like point core.hooksPath into the worktree) and checks symlinks out as plain files.
 */
class Git
{
    private const TOKEN_VARIABLE = 'PRISM_GIT_TOKEN';

    /**
     * Seconds a fetch or push may take.
     */
    public const NETWORK_TIMEOUT = 300;

    /**
     * Seconds adding or removing a worktree may take: it checks out or deletes every file.
     */
    public const WORKTREE_TIMEOUT = 180;

    /**
     * Seconds any other git command may take. They all only touch the local repository.
     */
    public const COMMAND_TIMEOUT = 30;

    /**
     * The longest fetchPullRequestHead() and addWorktree() take together.
     */
    public const PREPARE_WORKTREE_TIMEOUT = self::NETWORK_TIMEOUT + self::COMMAND_TIMEOUT + self::WORKTREE_TIMEOUT;

    /**
     * The longest removeWorktree() takes.
     */
    public const REMOVE_WORKTREE_TIMEOUT = self::WORKTREE_TIMEOUT + 2 * self::COMMAND_TIMEOUT;

    public function __construct(private readonly CommandLocator $locator) {}

    public function binary(): ?string
    {
        return $this->locator->find('git');
    }

    /**
     * Run git in a directory and return the result, throwing when git fails.
     *
     * @param  list<string>  $arguments
     * @param  array<string, string>  $environment
     */
    public function run(string $directory, array $arguments, #[\SensitiveParameter] array $environment = [], int $timeoutSeconds = self::COMMAND_TIMEOUT): ProcessResult
    {
        $binary = $this->binary() ?? throw new GitException('git was not found on this computer.');

        $result = Process::path($directory)
            ->env(ChildProcessEnvironment::make([
                'PATH' => $this->locator->searchPath([$binary]),
                'GIT_TERMINAL_PROMPT' => '0',
                ...self::safeEnvironment(),
                ...$environment,
            ]))
            ->timeout($timeoutSeconds)
            ->run([$binary, ...$arguments]);

        if ($result->failed()) {
            throw new GitException(trim($result->errorOutput()) ?: trim($result->output()) ?: 'git '.($arguments[0] ?? '').' failed.');
        }

        return $result;
    }

    /**
     * Environment variables that keep git from running anything a pull request brings along, for every git process
     * PRism starts and for Claude's: hooks come from an empty directory instead of the repository (core.hooksPath),
     * symlinks are checked out as plain files so nothing in a worktree leads outside it, and Git LFS doesn't download
     * from servers the repository names. Uses GIT_CONFIG_COUNT (git 2.31+) rather than /dev/null, which Windows lacks.
     *
     * @return array<string, string>
     */
    public static function safeEnvironment(): array
    {
        return [
            'GIT_CONFIG_COUNT' => '2',
            'GIT_CONFIG_KEY_0' => 'core.hooksPath',
            'GIT_CONFIG_VALUE_0' => self::emptyHooksDirectory(),
            'GIT_CONFIG_KEY_1' => 'core.symlinks',
            'GIT_CONFIG_VALUE_1' => 'false',
            'GIT_LFS_SKIP_SMUDGE' => '1',
        ];
    }

    /**
     * An empty directory PRism controls, used as git's hooks directory so no hook ever runs.
     */
    public static function emptyHooksDirectory(): string
    {
        $directory = storage_path('app/git-hooks');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        return $directory;
    }

    /**
     * The "owner/name" a checkout's origin remote points at on GitHub, read straight from .git/config.
     */
    public function githubRepositoryAt(string $directory): ?string
    {
        $config = $directory.DIRECTORY_SEPARATOR.'.git'.DIRECTORY_SEPARATOR.'config';

        if (! is_file($config)) {
            return null;
        }

        if (! preg_match('/\[remote "origin"\][^\[]*?url\s*=\s*(\S+)/s', (string) file_get_contents($config), $matches)) {
            return null;
        }

        return self::parseGithubRemote($matches[1]);
    }

    /**
     * Turn git@github.com:owner/name.git or https://github.com/owner/name(.git) into "owner/name".
     */
    public static function parseGithubRemote(string $url): ?string
    {
        if (! preg_match('#github\.com[:/]+([^/\s]+)/([^/\s]+?)(?:\.git)?/?$#i', $url, $matches)) {
            return null;
        }

        return "{$matches[1]}/{$matches[2]}";
    }

    /**
     * The ref a pull request's head is fetched into, so nothing else (like an editor fetching in the background, which
     * rewrites FETCH_HEAD) can change what PRism reads back.
     */
    public static function pullRequestRef(int $number): string
    {
        return "refs/prism/pr-{$number}";
    }

    /**
     * Fetch a pull request's head commit into a checkout and return its SHA. With $expectedSha, refuse a head that
     * isn't the commit PRism synced, e.g. because the author pushed since.
     *
     * @throws GitException
     */
    public function fetchPullRequestHead(string $checkout, string $repository, int $number, #[\SensitiveParameter] string $token, ?string $expectedSha = null): string
    {
        $ref = self::pullRequestRef($number);

        $this->run(
            $checkout,
            [...$this->authenticated(), 'fetch', '--no-tags', '--no-write-fetch-head', $this->httpsUrl($repository), "+pull/{$number}/head:{$ref}"],
            $this->tokenEnvironment($token),
            self::NETWORK_TIMEOUT,
        );

        $commit = trim($this->run($checkout, ['rev-parse', '--verify', '--end-of-options', "{$ref}^{commit}"])->output());

        if ($expectedSha !== null && strcasecmp($commit, $expectedSha) !== 0) {
            throw new GitException(sprintf(
                'Pull request #%d has new commits since PRism last synced it (GitHub has %s, PRism expected %s). Refresh it and try again.',
                $number,
                substr($commit, 0, 7),
                substr($expectedSha, 0, 7),
            ));
        }

        return $commit;
    }

    /**
     * Create a worktree for a commit, optionally on a new local branch.
     */
    public function addWorktree(string $checkout, string $path, string $commit, ?string $branch = null): void
    {
        $arguments = $branch === null
            ? ['worktree', 'add', '--detach', $path, $commit]
            : ['worktree', 'add', '-b', $branch, $path, $commit];

        $this->run($checkout, $arguments, timeoutSeconds: self::WORKTREE_TIMEOUT);
    }

    public function removeWorktree(string $checkout, string $path, ?string $branch = null): void
    {
        if (is_dir($path)) {
            $this->run($checkout, ['worktree', 'remove', '--force', $path], timeoutSeconds: self::WORKTREE_TIMEOUT);
        }

        $this->pruneWorktrees($checkout);

        if ($branch !== null) {
            rescue(fn () => $this->deleteBranch($checkout, $branch), report: false);
        }
    }

    /**
     * Forget worktrees whose directories are gone.
     */
    public function pruneWorktrees(string $checkout): void
    {
        $this->run($checkout, ['worktree', 'prune']);
    }

    /**
     * The paths of a checkout's linked worktrees, as git records them.
     *
     * @return list<string>
     */
    public function worktrees(string $checkout): array
    {
        preg_match_all('/^worktree (.+)$/m', $this->run($checkout, ['worktree', 'list', '--porcelain'])->output(), $matches);

        return array_map('trim', $matches[1]);
    }

    /**
     * Full names of a checkout's refs under a prefix, e.g. "refs/heads/prism/".
     *
     * @return list<string>
     */
    public function refs(string $checkout, string $prefix): array
    {
        $output = trim($this->run($checkout, ['for-each-ref', '--format=%(refname)', $prefix])->output());

        return $output === '' ? [] : array_values(array_filter(array_map('trim', explode("\n", $output))));
    }

    public function deleteRef(string $checkout, string $ref): void
    {
        $this->run($checkout, ['update-ref', '-d', $ref]);
    }

    public function deleteBranch(string $checkout, string $branch): void
    {
        $this->run($checkout, ['branch', '-D', $branch]);
    }

    /**
     * Push a commit to a branch on GitHub: exactly that commit, whatever the worktree's HEAD is now. Never forces.
     *
     * @throws GitException
     */
    public function push(string $directory, string $repository, string $commit, string $branch, #[\SensitiveParameter] string $token): void
    {
        if (! preg_match('/^[0-9a-f]{40}([0-9a-f]{24})?$/i', $commit)) {
            throw new GitException('PRism has no record of which commit you approved, so it did not push anything.');
        }

        $this->run(
            $directory,
            [...$this->authenticated(), 'push', '--no-verify', $this->httpsUrl($repository), "{$commit}:refs/heads/{$branch}"],
            $this->tokenEnvironment($token),
            self::NETWORK_TIMEOUT,
        );
    }

    public function hasUncommittedChanges(string $worktree): bool
    {
        return trim($this->run($worktree, ['status', '--porcelain'])->output()) !== '';
    }

    /**
     * Commit every change in the worktree. Falls back to the given identity when git has no user configured.
     */
    public function commitAll(string $worktree, string $message, string $fallbackName, string $fallbackEmail): void
    {
        $this->run($worktree, ['add', '--all']);

        $hasIdentity = rescue(fn (): bool => trim($this->run($worktree, ['config', 'user.email'])->output()) !== '', false, report: false);
        $identity = $hasIdentity ? [] : ['-c', "user.name={$fallbackName}", '-c', "user.email={$fallbackEmail}"];

        $this->run($worktree, [...$identity, 'commit', '--no-verify', '-m', $message]);
    }

    /**
     * The commit a worktree's HEAD points at.
     */
    public function head(string $worktree): string
    {
        return trim($this->run($worktree, ['rev-parse', 'HEAD'])->output());
    }

    /**
     * Commits made since a base commit, up to $head, oldest first.
     *
     * @return list<array{sha: string, message: string}>
     */
    public function commitsSince(string $worktree, string $base, string $head = 'HEAD'): array
    {
        $output = trim($this->run($worktree, ['log', '--reverse', '--format=%H%x1f%s', "{$base}..{$head}"])->output());

        return $output === '' ? [] : array_map(function (string $line): array {
            [$sha, $message] = explode("\x1f", $line, 2) + [1 => ''];

            return ['sha' => $sha, 'message' => $message];
        }, explode("\n", $output));
    }

    /**
     * The diff and its size from a base commit to $head.
     *
     * @return array{diff: string, files_changed: int, additions: int, deletions: int}
     */
    public function diffSince(string $worktree, string $base, string $head = 'HEAD'): array
    {
        $stat = $this->run($worktree, ['diff', '--shortstat', "{$base}..{$head}"])->output();
        preg_match('/(\d+) files? changed/', $stat, $files);
        preg_match('/(\d+) insertions?/', $stat, $additions);
        preg_match('/(\d+) deletions?/', $stat, $deletions);

        return [
            'diff' => $this->run($worktree, ['diff', '--no-color', "{$base}..{$head}"])->output(),
            'files_changed' => (int) ($files[1] ?? 0),
            'additions' => (int) ($additions[1] ?? 0),
            'deletions' => (int) ($deletions[1] ?? 0),
        ];
    }

    /**
     * The directory under storage that holds every worktree PRism creates.
     */
    public static function worktreesDirectory(): string
    {
        $directory = storage_path('app/worktrees');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        return $directory;
    }

    /**
     * A fresh directory under storage for a worktree.
     */
    public function worktreePath(string $repository, string $label): string
    {
        return self::worktreesDirectory().DIRECTORY_SEPARATOR.Str::slug(str_replace('/', '-', $repository)."-{$label}").'-'.Str::lower(Str::random(6));
    }

    /**
     * @return list<string>
     */
    private function authenticated(): array
    {
        return [
            '-c', 'credential.helper=',
            '-c', 'credential.helper=!f() { echo username=x-access-token; echo "password=$'.self::TOKEN_VARIABLE.'"; }; f',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function tokenEnvironment(#[\SensitiveParameter] string $token): array
    {
        return [self::TOKEN_VARIABLE => $token];
    }

    private function httpsUrl(string $repository): string
    {
        return "https://github.com/{$repository}.git";
    }
}
