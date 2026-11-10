<?php

use App\Enums\CodeChangeStatus;
use App\Enums\CodeChangeTarget;
use App\Jobs\PushCodeChange;
use App\Jobs\RemoveCodeChangeWorktree;
use App\Models\CodeChange;
use App\Models\GithubAccount;
use App\Models\PullRequest;
use App\Services\AppSettings;
use App\Services\Git\GitException;
use Illuminate\Http\Client\Request;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

const APPROVED_SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

beforeEach(function () {
    GithubAccount::factory()->create(['login' => 'octocat', 'token' => 'ghp_test']);
    app(AppSettings::class)->set(AppSettings::ALLOW_PUSHING, true);
    Http::preventStrayRequests();
    Queue::fake([RemoveCodeChangeWorktree::class]);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function pushingCodeChange(?PullRequest $pullRequest = null, array $attributes = []): CodeChange
{
    return CodeChange::factory()
        ->for($pullRequest ?? PullRequest::factory()->for(trackedRepository('acme/app'))->create(['head_ref' => 'feature/billing']))
        ->create(['status' => CodeChangeStatus::Pushing, 'head_sha' => APPROVED_SHA, ...$attributes]);
}

function pushCommand(PendingProcess $process): ?string
{
    return in_array('push', $process->command, true) ? implode(' ', $process->command) : null;
}

it('pushes exactly the commit the user approved onto the pull request branch', function () {
    Process::fake(['*' => Process::result('')]);
    $change = pushingCodeChange();

    PushCodeChange::dispatchSync($change);

    expect($change->fresh())
        ->status->toBe(CodeChangeStatus::Pushed)
        ->pushed_branch->toBe('feature/billing')
        ->error->toBeNull();
    Process::assertRan(fn (PendingProcess $process): bool => in_array('https://github.com/acme/app.git', $process->command, true)
        && in_array(APPROVED_SHA.':refs/heads/feature/billing', $process->command, true)
        && ! in_array('HEAD:refs/heads/feature/billing', $process->command, true));
    Queue::assertPushed(RemoveCodeChangeWorktree::class);
});

it('pushes to a new branch and opens a pull request into the original branch', function () {
    Process::fake(['*' => Process::result('')]);
    $change = pushingCodeChange(attributes: ['target' => CodeChangeTarget::NewPullRequest]);
    $branch = "prism/pr-{$change->pullRequest->number}-fixes-{$change->id}";
    Http::fake(['api.github.com/repos/acme/app/pulls' => Http::response(['html_url' => 'https://github.com/acme/app/pull/99'], 201)]);

    PushCodeChange::dispatchSync($change);

    expect($change->fresh())
        ->status->toBe(CodeChangeStatus::Pushed)
        ->pushed_branch->toBe($branch)
        ->created_pull_request_url->toBe('https://github.com/acme/app/pull/99');
    Http::assertSent(fn (Request $request): bool => $request['base'] === 'feature/billing' && $request['head'] === $branch);
    Process::assertRan(fn (PendingProcess $process): bool => in_array(APPROVED_SHA.":refs/heads/{$branch}", $process->command, true));
});

it('reuses the pull request opened by an earlier push instead of failing', function () {
    Process::fake(['*' => Process::result('')]);
    $change = pushingCodeChange(attributes: ['target' => CodeChangeTarget::NewPullRequest]);
    Http::fake([
        'api.github.com/repos/acme/app/pulls' => Http::response(['message' => 'Validation Failed', 'errors' => [['message' => 'A pull request already exists for acme:prism/pr-1-fixes-1.']]], 422),
        'api.github.com/graphql' => Http::response(['data' => ['repository' => ['pullRequests' => ['nodes' => [
            ['url' => 'https://github.com/acme/app/pull/12', 'headRepository' => ['nameWithOwner' => 'octo-fork/app']],
            ['url' => 'https://github.com/acme/app/pull/98', 'headRepository' => ['nameWithOwner' => 'acme/app']],
        ]]]]]),
    ]);

    PushCodeChange::dispatchSync($change);

    expect($change->fresh())
        ->status->toBe(CodeChangeStatus::Pushed)
        ->created_pull_request_url->toBe('https://github.com/acme/app/pull/98')
        ->error->toBeNull();
});

it('says the push went through when only opening the pull request failed', function () {
    Process::fake(['*' => Process::result('')]);
    $change = pushingCodeChange(attributes: ['target' => CodeChangeTarget::NewPullRequest]);
    Http::fake(['api.github.com/repos/acme/app/pulls' => Http::response(['message' => 'Server Error'], 500)]);

    PushCodeChange::dispatchSync($change);

    expect($change->fresh())
        ->status->toBe(CodeChangeStatus::Pushed)
        ->created_pull_request_url->toBeNull()
        ->error->toStartWith("Pushed to prism/pr-{$change->pullRequest->number}-fixes-{$change->id}, but GitHub didn't open the pull request")
        ->error->not->toContain('Push failed');
});

it('keeps the changes and explains why when the push fails', function () {
    Process::fake(['*push*' => Process::result(errorOutput: 'rejected: non-fast-forward', exitCode: 1), '*' => Process::result('')]);
    $change = pushingCodeChange();

    expect(fn () => PushCodeChange::dispatchSync($change))->toThrow(GitException::class);

    expect($change->fresh())
        ->status->toBe(CodeChangeStatus::Ready)
        ->error->toBe('Push failed: rejected: non-fast-forward');
});

it('checks again that pushing is allowed when the job runs', function () {
    Process::fake();
    $change = pushingCodeChange();
    app(AppSettings::class)->set(AppSettings::ALLOW_PUSHING, false);

    PushCodeChange::dispatchSync($change);

    expect($change->fresh())
        ->status->toBe(CodeChangeStatus::Ready)
        ->error->toBe('Pushing is turned off in Settings → PR review.');
    Process::assertNothingRan();
});

it('pushes a fix for a pull request from a fork to the fork, when its author allows maintainer edits', function () {
    Process::fake(['*' => Process::result('')]);
    $pullRequest = PullRequest::factory()->for(trackedRepository('acme/app'))->fromFork('octo-fork/app', maintainerCanModify: true)->create(['head_ref' => 'main']);
    $change = pushingCodeChange($pullRequest);

    PushCodeChange::dispatchSync($change);

    expect($change->fresh()->status)->toBe(CodeChangeStatus::Pushed);
    Process::assertRan(fn (PendingProcess $process): bool => in_array('https://github.com/octo-fork/app.git', $process->command, true)
        && in_array(APPROVED_SHA.':refs/heads/main', $process->command, true));
    Process::assertDidntRun(fn (PendingProcess $process): bool => str_contains((string) pushCommand($process), 'acme/app.git'));
});

it('never pushes code from a fork to the pull request repository', function (CodeChangeTarget $target, bool $maintainerCanModify, string $reason) {
    Process::fake();
    $pullRequest = PullRequest::factory()->for(trackedRepository('acme/app'))->fromFork('octo-fork/app', $maintainerCanModify)->create(['head_ref' => 'main']);
    $change = pushingCodeChange($pullRequest, ['target' => $target]);

    PushCodeChange::dispatchSync($change);

    expect($change->fresh())
        ->status->toBe(CodeChangeStatus::Ready)
        ->error->toContain($reason);
    Process::assertNothingRan();
    Http::assertNothingSent();
})->with([
    'onto its branch without maintainer edits' => [CodeChangeTarget::PullRequestBranch, false, "doesn't allow edits from maintainers"],
    'as a new pull request' => [CodeChangeTarget::NewPullRequest, true, "won't push its code to a new branch"],
]);

it('does not push a change whose diff was too big to show in full', function () {
    Process::fake();
    $change = pushingCodeChange(attributes: ['diff_truncated' => true]);

    PushCodeChange::dispatchSync($change);

    expect($change->fresh())
        ->status->toBe(CodeChangeStatus::Ready)
        ->error->toContain('too big for PRism to show in full');
    Process::assertNothingRan();
});

it('does nothing for changes that are no longer waiting to be pushed', function () {
    Process::fake();
    $change = pushingCodeChange(attributes: ['status' => CodeChangeStatus::Discarded]);

    PushCodeChange::dispatchSync($change);

    expect($change->fresh()->status)->toBe(CodeChangeStatus::Discarded);
    Process::assertNothingRan();
});
