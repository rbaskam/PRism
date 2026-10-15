<?php

namespace App\Services\Git;

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
 */
class Git
{
    private const TOKEN_VARIABLE = 'PRISM_GIT_TOKEN';

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
    public function run(string $directory, array $arguments, array $environment = [], int $timeoutSeconds = 120): ProcessResult
    {
        $binary = $this->binary() ?? throw new GitException('git was not found on this computer.');

        $result = Process::path($directory)
            ->env([
                'PATH' => $this->locator->searchPath([$binary]),
                'GIT_TERMINAL_PROMPT' => '0',
                ...$environment,
            ])
            ->timeout($timeoutSeconds)
            ->run([$binary, ...$arguments]);

        if ($result->failed()) {
            throw new GitException(trim($result->errorOutput()) ?: trim($result->output()) ?: 'git '.($arguments[0] ?? '').' failed.');
        }

        return $result;
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
     * Fetch a pull request's head commit into a checkout and return its SHA.
     */
    public function fetchPullRequestHead(string $checkout, string $repository, int $number, string $token): string
    {
        $this->run($checkout, [...$this->authenticated(), 'fetch', '--no-tags', $this->httpsUrl($repository), "pull/{$number}/head"], $this->tokenEnvironment($token), 300);

        return trim($this->run($checkout, ['rev-parse', 'FETCH_HEAD'])->output());
    }

    /**
     * Create a worktree for a commit, optionally on a new local branch.
     */
    public function addWorktree(string $checkout, string $path, string $commit, ?string $branch = null): void
    {
        $arguments = $branch === null
            ? ['worktree', 'add', '--detach', $path, $commit]
            : ['worktree', 'add', '-b', $branch, $path, $commit];

        $this->run($checkout, $arguments, timeoutSeconds: 300);
    }

    public function removeWorktree(string $checkout, string $path, ?string $branch = null): void
    {
        if (is_dir($path)) {
            $this->run($checkout, ['worktree', 'remove', '--force', $path]);
        }

        $this->run($checkout, ['worktree', 'prune']);

        if ($branch !== null) {
            rescue(fn () => $this->run($checkout, ['branch', '-D', $branch]), report: false);
        }
    }

    /**
     * Push the worktree's HEAD to a branch on GitHub. Never forces.
     */
    public function push(string $worktree, string $repository, string $branch, string $token): void
    {
        $this->run($worktree, [...$this->authenticated(), 'push', $this->httpsUrl($repository), "HEAD:refs/heads/{$branch}"], $this->tokenEnvironment($token), 300);
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
     * Commits made since a base commit, oldest first.
     *
     * @return list<array{sha: string, message: string}>
     */
    public function commitsSince(string $worktree, string $base): array
    {
        $output = trim($this->run($worktree, ['log', '--reverse', '--format=%H%x1f%s', "{$base}..HEAD"])->output());

        return $output === '' ? [] : array_map(function (string $line): array {
            [$sha, $message] = explode("\x1f", $line, 2) + [1 => ''];

            return ['sha' => $sha, 'message' => $message];
        }, explode("\n", $output));
    }

    /**
     * The diff and its size since a base commit.
     *
     * @return array{diff: string, files_changed: int, additions: int, deletions: int}
     */
    public function diffSince(string $worktree, string $base): array
    {
        $stat = $this->run($worktree, ['diff', '--shortstat', "{$base}..HEAD"])->output();
        preg_match('/(\d+) files? changed/', $stat, $files);
        preg_match('/(\d+) insertions?/', $stat, $additions);
        preg_match('/(\d+) deletions?/', $stat, $deletions);

        return [
            'diff' => $this->run($worktree, ['diff', '--no-color', "{$base}..HEAD"])->output(),
            'files_changed' => (int) ($files[1] ?? 0),
            'additions' => (int) ($additions[1] ?? 0),
            'deletions' => (int) ($deletions[1] ?? 0),
        ];
    }

    /**
     * A fresh directory under storage for a worktree.
     */
    public function worktreePath(string $repository, string $label): string
    {
        $directory = storage_path('app/worktrees');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        return $directory.DIRECTORY_SEPARATOR.Str::slug(str_replace('/', '-', $repository)."-{$label}").'-'.Str::lower(Str::random(6));
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
    private function tokenEnvironment(string $token): array
    {
        return [self::TOKEN_VARIABLE => $token];
    }

    private function httpsUrl(string $repository): string
    {
        return "https://github.com/{$repository}.git";
    }
}
