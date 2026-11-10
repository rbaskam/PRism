<?php

use App\Enums\ClaudeAuthMode;
use App\Enums\CodeChangeStatus;
use App\Jobs\MakeCodeChanges;
use App\Models\CodeChange;
use App\Models\GithubAccount;
use App\Models\PullRequest;
use App\Services\AppSettings;
use App\Services\Claude\ClaudeCode;
use App\Services\Git\Git;
use App\Services\Git\GitException;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    GithubAccount::factory()->create(['login' => 'octocat', 'token' => 'ghp_test']);
    app(AppSettings::class)->set(AppSettings::CLAUDE_AUTH_MODE, ClaudeAuthMode::CliLogin->value);
    app(AppSettings::class)->set(AppSettings::CLAUDE_BINARY_PATH, PHP_BINARY);
});

function fakeGitAndClaude(string $log, string $head = 'basesha', string $diff = "diff --git a/app/Foo.php b/app/Foo.php\n+fixed"): void
{
    Process::fake([
        '*rev-parse*refs/prism/pr-*' => Process::result($head),
        '*rev-parse*HEAD*' => Process::result('headsha'),
        '*status*--porcelain*' => Process::result(''),
        '*log*--reverse*' => Process::result($log),
        '*diff*--shortstat*' => Process::result(' 2 files changed, 10 insertions(+), 3 deletions(-)'),
        '*diff*--no-color*' => Process::result($diff),
        '*--output-format*' => Process::result(json_encode([
            'type' => 'result', 'subtype' => 'success', 'is_error' => false, 'result' => '',
            'structured_output' => ['summary' => 'Added the missing null check.', 'commit_message' => 'fix: null check'],
            'total_cost_usd' => 0.12, 'duration_ms' => 60000,
        ])),
        '*' => Process::result(''),
    ]);
}

/**
 * A change for a pull request whose synced head is the commit fakeGitAndClaude() fetches.
 */
function queuedCodeChange(): CodeChange
{
    return CodeChange::factory()->queued()
        ->for(PullRequest::factory()->state(['head_sha' => 'basesha']))
        ->create(['finding_ids' => []]);
}

it('lets Claude commit changes in a worktree and keeps them for review without pushing', function () {
    fakeGitAndClaude("abc123\x1ffix: add null check");
    $change = queuedCodeChange();

    MakeCodeChanges::dispatchSync($change);

    expect($change->fresh())
        ->status->toBe(CodeChangeStatus::Ready)
        ->base_sha->toBe('basesha')
        ->head_sha->toBe('headsha')
        ->commits->toBe([['sha' => 'abc123', 'message' => 'fix: add null check']])
        ->files_changed->toBe(2)
        ->additions->toBe(10)
        ->deletions->toBe(3)
        ->diff_truncated->toBeFalse()
        ->summary->toBe('Added the missing null check.');
    Process::assertRan(fn (PendingProcess $process): bool => in_array('worktree', $process->command, true) && in_array('add', $process->command, true));
    Process::assertRan(fn (PendingProcess $process): bool => in_array('--tools', $process->command, true)
        && $process->command[array_search('--tools', $process->command) + 1] === 'Bash,Read,Grep,Glob,Edit,Write');
    Process::assertDidntRun(fn (PendingProcess $process): bool => in_array('push', $process->command, true));
});

it('lets Claude edit files only inside the worktree', function () {
    fakeGitAndClaude("abc123\x1ffix: add null check");
    $change = queuedCodeChange();

    MakeCodeChanges::dispatchSync($change);

    $worktree = $change->fresh()->worktree_path;
    Process::assertRan(function (PendingProcess $process) use ($worktree): bool {
        $command = $process->command;

        if (! in_array('--allowedTools', $command, true)) {
            return false;
        }

        $allowed = array_slice($command, array_search('--allowedTools', $command) + 1, array_search('--disallowedTools', $command) - array_search('--allowedTools', $command) - 1);

        return $process->path === $worktree
            && in_array('Edit(./**)', $allowed, true)
            && in_array('Read(./**)', $allowed, true)
            && array_intersect(['Read', 'Edit', 'Write', 'Grep', 'Glob'], $allowed) === []
            && json_decode($command[array_search('--settings', $command) + 1], true)['permissions']['blockWritesOutsideWorkingDirectories'] === true;
    });
});

it('fetches with the GitHub token from the environment, never in the command', function () {
    fakeGitAndClaude('');
    $change = queuedCodeChange();

    MakeCodeChanges::dispatchSync($change);

    Process::assertRan(fn (PendingProcess $process): bool => in_array('fetch', $process->command, true)
        && $process->environment['PRISM_GIT_TOKEN'] === 'ghp_test'
        && ! str_contains(implode(' ', $process->command), 'ghp_test'));
});

it('refuses to change a pull request that has moved on since PRism synced it', function () {
    fakeGitAndClaude("abc123\x1ffix: add null check", head: 'newersha');
    $change = queuedCodeChange();

    expect(fn () => MakeCodeChanges::dispatchSync($change))->toThrow(GitException::class, 'has new commits since PRism last synced it');

    expect($change->fresh())
        ->status->toBe(CodeChangeStatus::Failed)
        ->error->toContain('Refresh it and try again');
    Process::assertDidntRun(fn (PendingProcess $process): bool => in_array('--output-format', $process->command, true));
});

it('marks a change whose diff is too big to show in full, so it cannot be pushed', function () {
    fakeGitAndClaude("abc123\x1ffix: add null check", diff: str_repeat('+', MakeCodeChanges::MAX_STORED_DIFF_BYTES + 1));
    $change = queuedCodeChange();

    MakeCodeChanges::dispatchSync($change);

    expect($change->fresh())
        ->status->toBe(CodeChangeStatus::Ready)
        ->diff_truncated->toBeTrue()
        ->pushRefusal()->toContain('too big for PRism to show in full');
});

it('reports when Claude made no changes and removes the worktree', function () {
    fakeGitAndClaude('');
    $change = queuedCodeChange();

    MakeCodeChanges::dispatchSync($change);

    expect($change->fresh()->status)->toBe(CodeChangeStatus::NoChanges);
    Process::assertRan(fn (PendingProcess $process): bool => in_array('worktree', $process->command, true) && in_array('prune', $process->command, true));
});

it('leaves Claude enough time to commit and clean up before the job runs out of time', function () {
    $job = new MakeCodeChanges(CodeChange::factory()->queued()->make());

    expect(Git::PREPARE_WORKTREE_TIMEOUT + ClaudeCode::MINIMUM_TIMEOUT_SECONDS + MakeCodeChanges::FINISHING_SECONDS)->toBeLessThanOrEqual($job->timeout);
});
