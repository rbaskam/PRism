<?php

use App\Enums\ClaudeAuthMode;
use App\Enums\CodeChangeStatus;
use App\Enums\CodeChangeTarget;
use App\Jobs\MakeCodeChanges;
use App\Jobs\PushCodeChange;
use App\Models\CodeChange;
use App\Models\GithubAccount;
use App\Services\AppSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    GithubAccount::factory()->create(['login' => 'octocat', 'token' => 'gho_secret']);
    app(AppSettings::class)->set(AppSettings::CLAUDE_AUTH_MODE, ClaudeAuthMode::CliLogin->value);
    app(AppSettings::class)->set(AppSettings::CLAUDE_BINARY_PATH, PHP_BINARY);
});

function fakeGitAndClaude(string $log): void
{
    Process::fake([
        '*rev-parse*FETCH_HEAD*' => Process::result('basesha'),
        '*rev-parse*HEAD*' => Process::result('headsha'),
        '*status*--porcelain*' => Process::result(''),
        '*log*--reverse*' => Process::result($log),
        '*diff*--shortstat*' => Process::result(' 2 files changed, 10 insertions(+), 3 deletions(-)'),
        '*diff*--no-color*' => Process::result("diff --git a/app/Foo.php b/app/Foo.php\n+fixed"),
        '*--output-format*' => Process::result(json_encode([
            'type' => 'result', 'subtype' => 'success', 'is_error' => false, 'result' => '',
            'structured_output' => ['summary' => 'Added the missing null check.', 'commit_message' => 'fix: null check'],
            'total_cost_usd' => 0.12, 'duration_ms' => 60000,
        ])),
        '*' => Process::result(''),
    ]);
}

it('lets Claude commit changes in a worktree and keeps them for review without pushing', function () {
    fakeGitAndClaude("abc123\x1ffix: add null check");
    $change = CodeChange::factory()->queued()->create(['finding_ids' => []]);

    MakeCodeChanges::dispatchSync($change);

    expect($change->fresh())
        ->status->toBe(CodeChangeStatus::Ready)
        ->base_sha->toBe('basesha')
        ->head_sha->toBe('headsha')
        ->commits->toBe([['sha' => 'abc123', 'message' => 'fix: add null check']])
        ->files_changed->toBe(2)
        ->additions->toBe(10)
        ->deletions->toBe(3)
        ->summary->toBe('Added the missing null check.');
    Process::assertRan(fn (PendingProcess $process): bool => in_array('worktree', $process->command, true) && in_array('add', $process->command, true));
    Process::assertRan(fn (PendingProcess $process): bool => in_array('--tools', $process->command, true)
        && $process->command[array_search('--tools', $process->command) + 1] === 'Bash,Read,Grep,Glob,Edit,Write');
    Process::assertDidntRun(fn (PendingProcess $process): bool => in_array('push', $process->command, true));
});

it('fetches with the GitHub token from the environment, never in the command', function () {
    fakeGitAndClaude('');
    $change = CodeChange::factory()->queued()->create();

    MakeCodeChanges::dispatchSync($change);

    Process::assertRan(fn (PendingProcess $process): bool => in_array('fetch', $process->command, true)
        && $process->environment['PRISM_GIT_TOKEN'] === 'gho_secret'
        && ! str_contains(implode(' ', $process->command), 'gho_secret'));
});

it('reports when Claude made no changes and removes the worktree', function () {
    fakeGitAndClaude('');
    $change = CodeChange::factory()->queued()->create();

    MakeCodeChanges::dispatchSync($change);

    expect($change->fresh()->status)->toBe(CodeChangeStatus::NoChanges);
    Process::assertRan(fn (PendingProcess $process): bool => in_array('worktree', $process->command, true) && in_array('prune', $process->command, true));
});

it('pushes to a new branch and opens a pull request into the original branch', function () {
    Http::preventStrayRequests();
    Process::fake(['*' => Process::result('')]);
    $change = CodeChange::factory()->create(['target' => CodeChangeTarget::NewPullRequest, 'status' => CodeChangeStatus::Pushing]);
    $pullRequest = $change->pullRequest;
    Http::fake([
        "api.github.com/repos/{$pullRequest->repository->full_name}/pulls" => Http::response(['html_url' => 'https://github.com/acme/app/pull/99'], 201),
    ]);

    PushCodeChange::dispatchSync($change);

    expect($change->fresh())
        ->status->toBe(CodeChangeStatus::Pushed)
        ->pushed_branch->toBe("prism/pr-{$pullRequest->number}-fixes-{$change->id}")
        ->created_pull_request_url->toBe('https://github.com/acme/app/pull/99');
    Http::assertSent(fn (Request $request): bool => $request['base'] === $pullRequest->head_ref
        && $request['head'] === "prism/pr-{$pullRequest->number}-fixes-{$change->id}");
    Process::assertRan(fn (PendingProcess $process): bool => in_array('push', $process->command, true)
        && in_array("HEAD:refs/heads/prism/pr-{$pullRequest->number}-fixes-{$change->id}", $process->command, true));
});
