<?php

use App\Enums\CodeChangeTarget;
use App\Http\Resources\PullRequestResource;
use App\Models\GithubAccount;
use App\Models\PullRequest;
use App\Services\Github\PullRequestMapper;

it('records whether a pull request comes from a fork and whether maintainers may push to it', function () {
    $account = GithubAccount::factory()->make(['login' => 'octocat']);

    $attributes = app(PullRequestMapper::class)->attributes(githubPullRequestNode([
        'isCrossRepository' => true,
        'headRepository' => ['nameWithOwner' => 'octo-fork/app'],
        'maintainerCanModify' => true,
    ]), $account);

    expect($attributes)
        ->is_cross_repository->toBeTrue()
        ->head_repository->toBe('octo-fork/app')
        ->maintainer_can_modify->toBeTrue();
});

it('leaves the head repository empty when the fork was deleted', function () {
    $attributes = app(PullRequestMapper::class)->attributes(
        githubPullRequestNode(['isCrossRepository' => true, 'headRepository' => null]),
        GithubAccount::factory()->make(['login' => 'octocat']),
    );

    expect($attributes)
        ->is_cross_repository->toBeTrue()
        ->head_repository->toBeNull();
});

it('tells the page where Claude may send changes for a pull request', function (?bool $isCrossRepository, bool $maintainerCanModify, array $refused) {
    $pullRequest = PullRequest::factory()->create([
        'is_cross_repository' => $isCrossRepository,
        'head_repository' => $isCrossRepository ? 'octo-fork/app' : null,
        'maintainer_can_modify' => $maintainerCanModify,
    ]);

    $refusals = PullRequestResource::make($pullRequest)->resolve()['code_change_refusals'];

    expect(array_keys(array_filter($refusals)))->toBe($refused);
})->with([
    'same repository' => [false, false, []],
    'fork allowing maintainer edits' => [true, true, [CodeChangeTarget::NewPullRequest->value]],
    'fork without maintainer edits' => [true, false, [CodeChangeTarget::PullRequestBranch->value, CodeChangeTarget::NewPullRequest->value]],
    'not synced since PRism learned about forks' => [null, false, [CodeChangeTarget::PullRequestBranch->value, CodeChangeTarget::NewPullRequest->value]],
]);
