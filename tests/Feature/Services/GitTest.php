<?php

use App\Services\Git\Git;
use App\Services\Git\GitException;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

it('reads the GitHub repository from a remote URL', function (string $url, ?string $expected) {
    expect(Git::parseGithubRemote($url))->toBe($expected);
})->with([
    'ssh' => ['git@github.com:acme/billing-api.git', 'acme/billing-api'],
    'https' => ['https://github.com/acme/billing-api.git', 'acme/billing-api'],
    'https without .git' => ['https://github.com/acme/billing-api', 'acme/billing-api'],
    'ssh url form' => ['ssh://git@github.com/acme/billing-api.git', 'acme/billing-api'],
    'not github' => ['git@gitlab.com:acme/billing-api.git', null],
]);

it('runs every git command without hooks, symlinks or PRism secrets', function () {
    Process::fake(['*' => Process::result('')]);
    putenv('NATIVEPHP_SECRET=native-secret');

    try {
        app(Git::class)->hasUncommittedChanges(sys_get_temp_dir());
    } finally {
        putenv('NATIVEPHP_SECRET');
    }

    Process::assertRan(fn (PendingProcess $process): bool => $process->environment['GIT_CONFIG_COUNT'] === '2'
        && $process->environment['GIT_CONFIG_KEY_0'] === 'core.hooksPath'
        && $process->environment['GIT_CONFIG_VALUE_0'] === Git::emptyHooksDirectory()
        && $process->environment['GIT_CONFIG_KEY_1'] === 'core.symlinks'
        && $process->environment['GIT_CONFIG_VALUE_1'] === 'false'
        && $process->environment['NATIVEPHP_SECRET'] === false
        && ($process->environment['PRISM_GIT_TOKEN'] ?? false) === false);
});

it("fetches a pull request's head into its own ref and checks it is the synced commit", function () {
    Process::fake(['*rev-parse*' => Process::result("abc123\n"), '*' => Process::result('')]);

    $commit = app(Git::class)->fetchPullRequestHead(sys_get_temp_dir(), 'acme/app', 7, 'ghp_test', 'abc123');

    expect($commit)->toBe('abc123');
    Process::assertRan(fn (PendingProcess $process): bool => in_array('+pull/7/head:refs/prism/pr-7', $process->command, true)
        && in_array('--no-write-fetch-head', $process->command, true)
        && $process->environment['PRISM_GIT_TOKEN'] === 'ghp_test');
    Process::assertRan(fn (PendingProcess $process): bool => in_array('refs/prism/pr-7^{commit}', $process->command, true));
    Process::assertDidntRun(fn (PendingProcess $process): bool => in_array('FETCH_HEAD', $process->command, true));
});

it('refuses a pull request head that is not the synced commit', function () {
    Process::fake(['*rev-parse*' => Process::result("def456\n"), '*' => Process::result('')]);

    expect(fn () => app(Git::class)->fetchPullRequestHead(sys_get_temp_dir(), 'acme/app', 7, 'ghp_test', 'abc123'))
        ->toThrow(GitException::class, 'Pull request #7 has new commits since PRism last synced it (GitHub has def456, PRism expected abc123).');
});

it('pushes the approved commit, never HEAD, and refuses anything that is not a commit SHA', function () {
    Process::fake(['*' => Process::result('')]);
    $sha = str_repeat('b', 40);

    app(Git::class)->push(sys_get_temp_dir(), 'acme/app', $sha, 'feature/billing', 'ghp_test');

    Process::assertRan(fn (PendingProcess $process): bool => in_array("{$sha}:refs/heads/feature/billing", $process->command, true)
        && in_array('https://github.com/acme/app.git', $process->command, true)
        && ! str_contains(implode(' ', $process->command), 'ghp_test'));
    expect(fn () => app(Git::class)->push(sys_get_temp_dir(), 'acme/app', 'HEAD', 'feature/billing', 'ghp_test'))
        ->toThrow(GitException::class);
});
