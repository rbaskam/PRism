<?php

use App\Enums\CodeChangeStatus;
use App\Enums\CodeChangeTarget;
use App\Models\CodeChange;
use App\Models\CodeQuestion;
use App\Models\PendingReviewComment;
use App\Models\PullRequest;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Http::preventStrayRequests();
    onboardedAccount(['login' => 'octocat']);
});

it('sends the pull request\'s pending review comments in the order they were written', function () {
    $this->freezeTime();
    $pullRequest = PullRequest::factory()->create();
    $first = PendingReviewComment::factory()->for($pullRequest)->create(['path' => 'app/Foo.php', 'side' => 'LEFT', 'start_line' => 3, 'line' => 5, 'body' => 'Rename this.']);
    $second = PendingReviewComment::factory()->for($pullRequest)->create();
    PendingReviewComment::factory()->create();

    $response = $this->get(route('pull-requests.show', $pullRequest));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('pendingComments', 2)
        ->has('pendingComments.0', fn (Assert $comment) => $comment
            ->where('id', $first->id)
            ->where('path', 'app/Foo.php')
            ->where('side', 'LEFT')
            ->where('start_line', 3)
            ->where('line', 5)
            ->where('body', 'Rename this.')
            ->where('created_at', now()->toIso8601String()))
        ->where('pendingComments.1.id', $second->id));
});

it('sends the questions asked about the code with their follow-ups, answers and the lines they point at', function () {
    $this->freezeTime();
    $pullRequest = PullRequest::factory()->create();
    $question = CodeQuestion::factory()->for($pullRequest)->create([
        'path' => 'app/Foo.php',
        'start_line' => 12,
        'end_line' => 18,
        'prompt' => 'Why loop here?',
        'answer' => 'To add up the prices.',
        'suggested_code' => 'return collect($items)->sum("price");',
        'head_sha' => 'abc123',
        'model' => 'sonnet',
        'cost_usd' => 0.12,
        'duration_ms' => 3400,
        'started_at' => now(),
    ]);
    $followUp = CodeQuestion::factory()->for($pullRequest)->queued()->create([
        'parent_id' => $question->id,
        'path' => 'app/Foo.php',
        'side' => CodeQuestion::SIDE_OLD,
        'start_line' => 12,
        'end_line' => 12,
    ]);
    CodeQuestion::factory()->create();

    $response = $this->get(route('pull-requests.show', $pullRequest));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('codeQuestions', 2)
        ->has('codeQuestions.0', fn (Assert $sent) => $sent
            ->where('id', $question->id)
            ->where('parent_id', null)
            ->where('location', 'app/Foo.php:12-18')
            ->where('side', 'RIGHT')
            ->where('prompt', 'Why loop here?')
            ->where('status', 'completed')
            ->where('is_active', false)
            ->where('answer', 'To add up the prices.')
            ->where('suggested_code', 'return collect($items)->sum("price");')
            ->where('head_sha', 'abc123')
            ->where('model', 'sonnet')
            ->where('cost_usd', 0.12)
            ->where('duration_ms', 3400)
            ->where('started_at', now()->toIso8601String())
            ->where('created_at', now()->toIso8601String())
            ->etc())
        ->has('codeQuestions.1', fn (Assert $sent) => $sent
            ->where('id', $followUp->id)
            ->where('parent_id', $question->id)
            ->where('location', 'app/Foo.php:12')
            ->where('side', 'LEFT')
            ->where('status', 'queued')
            ->where('is_active', true)
            ->where('answer', null)
            ->where('started_at', null)
            ->etc()));
});

it('sends code changes newest first with the branch they will be pushed to, leaving out discarded ones', function () {
    $this->freezeTime();
    $pullRequest = PullRequest::factory()->create(['number' => 42, 'head_ref' => 'feature/search']);
    $onBranch = CodeChange::factory()->for($pullRequest)->create([
        'target' => CodeChangeTarget::PullRequestBranch,
        'finding_ids' => [3, 5],
        'comments' => [['author' => 'hubot', 'body' => 'Fix the typo.']],
        'pushed_branch' => 'feature/search',
        'pushed_at' => now(),
        'started_at' => now(),
    ]);
    CodeChange::factory()->for($pullRequest)->create(['status' => CodeChangeStatus::Discarded]);
    $newPullRequest = CodeChange::factory()->for($pullRequest)->queued()->create(['target' => CodeChangeTarget::NewPullRequest]);
    CodeChange::factory()->create();

    $response = $this->get(route('pull-requests.show', $pullRequest));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('codeChanges', 2)
        ->has('codeChanges.0', fn (Assert $change) => $change
            ->where('id', $newPullRequest->id)
            ->where('status', 'queued')
            ->where('is_active', true)
            ->where('target', 'new_pull_request')
            ->where('destination_branch', "prism/pr-42-fixes-{$newPullRequest->id}")
            ->where('finding_ids', [])
            ->where('comments', [])
            ->where('commits', [])
            ->where('diff', null)
            ->where('started_at', null)
            ->where('pushed_at', null)
            ->etc())
        ->has('codeChanges.1', fn (Assert $change) => $change
            ->where('id', $onBranch->id)
            ->where('status', 'ready')
            ->where('is_active', false)
            ->where('target', 'pull_request_branch')
            ->where('destination_branch', 'feature/search')
            ->where('instructions', 'Fix the failing null check.')
            ->where('finding_ids', [3, 5])
            ->where('comments.0.body', 'Fix the typo.')
            ->where('summary', 'Added a null check.')
            ->has('commits', 1)
            ->where('files_changed', 1)
            ->where('additions', 1)
            ->where('deletions', 1)
            ->where('pushed_branch', 'feature/search')
            ->where('pushed_at', now()->toIso8601String())
            ->where('started_at', now()->toIso8601String())
            ->where('created_at', now()->toIso8601String())
            ->etc()));
});

it('sends only the ten most recent code changes', function () {
    $pullRequest = PullRequest::factory()->create();
    $oldest = CodeChange::factory()->for($pullRequest)->create();
    CodeChange::factory()->for($pullRequest)->count(10)->create();

    $response = $this->get(route('pull-requests.show', $pullRequest));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('codeChanges', 10)
        ->where('codeChanges', fn ($changes): bool => collect($changes)->pluck('id')->doesntContain($oldest->id)));
});

it('sends empty lists when nothing has been drafted, asked or changed yet', function () {
    $pullRequest = PullRequest::factory()->create();

    $response = $this->get(route('pull-requests.show', $pullRequest));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('pendingComments', [])
        ->where('codeQuestions', [])
        ->where('codeChanges', []));
});
