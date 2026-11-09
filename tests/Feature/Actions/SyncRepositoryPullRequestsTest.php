<?php

use App\Actions\SyncRepositoryPullRequests;
use App\Enums\ReviewAttention;
use App\Models\GithubAccount;
use App\Models\PullRequest;
use App\Models\Repository;
use App\Services\AppSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

it('marks pull requests requested from the user directly or through one of their teams', function () {
    $account = GithubAccount::factory()->inTeam('acme', 'reviewers')->create(['login' => 'octocat']);
    $repository = Repository::factory()->create(['owner' => 'acme', 'name' => 'app']);
    Http::fake(['api.github.com/graphql' => Http::response(githubOpenPullRequestsResponse([
        githubPullRequestNode(['number' => 1, 'reviewRequests' => ['nodes' => [
            ['requestedReviewer' => ['__typename' => 'User', 'login' => 'octocat']],
        ]]]),
        githubPullRequestNode(['number' => 2, 'reviewRequests' => ['nodes' => [
            ['requestedReviewer' => ['__typename' => 'Team', 'slug' => 'reviewers', 'name' => 'Reviewers', 'organization' => ['login' => 'acme']]],
        ]]]),
        githubPullRequestNode(['number' => 3, 'reviewRequests' => ['nodes' => [
            ['requestedReviewer' => ['__typename' => 'Team', 'slug' => 'designers', 'name' => 'Designers', 'organization' => ['login' => 'acme']]],
        ]]]),
    ]))]);

    app(SyncRepositoryPullRequests::class)->handle($repository, $account);

    $pullRequests = $repository->pullRequests()->get()->keyBy('number');
    expect($pullRequests[1])
        ->is_review_requested_from_me->toBeTrue()
        ->is_review_requested_directly->toBeTrue()
        ->requested_via_teams->toBe([]);
    expect($pullRequests[2])
        ->is_review_requested_from_me->toBeTrue()
        ->is_review_requested_directly->toBeFalse()
        ->requested_via_teams->toBe(['acme/reviewers']);
    expect($pullRequests[3])
        ->is_review_requested_from_me->toBeFalse()
        ->attention()->toBe(ReviewAttention::None);
});

it('shows a pull request as approved when the user approved it, even after a later comment', function () {
    $account = GithubAccount::factory()->create(['login' => 'octocat']);
    $repository = Repository::factory()->create();
    Http::fake(['api.github.com/graphql' => Http::response(githubOpenPullRequestsResponse([
        githubPullRequestNode([
            'latestOpinionatedReviews' => ['nodes' => [
                ['author' => ['login' => 'octocat'], 'state' => 'APPROVED', 'submittedAt' => '2026-10-02T09:00:00Z', 'commit' => ['oid' => 'oldsha']],
            ]],
            'latestReviews' => ['nodes' => [
                ['author' => ['login' => 'octocat'], 'state' => 'COMMENTED', 'submittedAt' => '2026-10-02T09:30:00Z', 'commit' => ['oid' => 'headsha']],
            ]],
        ]),
    ]))]);

    app(SyncRepositoryPullRequests::class)->handle($repository, $account);

    $pullRequest = $repository->pullRequests()->sole();
    expect($pullRequest->attention())->toBe(ReviewAttention::Approved)
        ->and($pullRequest->hasCommitsSinceMyReview())->toBeTrue();
});

it('ignores a review request the user already answered that GitHub still lists', function () {
    $account = GithubAccount::factory()->inTeam('acme', 'reviewers')->create(['login' => 'octocat']);
    $repository = Repository::factory()->create();
    $team = ['__typename' => 'Team', 'slug' => 'reviewers', 'name' => 'Reviewers', 'organization' => ['login' => 'acme']];
    Http::fake(['api.github.com/graphql' => Http::response(githubOpenPullRequestsResponse([
        githubPullRequestNode([
            'reviewRequests' => ['nodes' => [['requestedReviewer' => $team]]],
            'reviewRequestedEvents' => ['nodes' => [['createdAt' => '2026-10-01T10:00:00Z', 'requestedReviewer' => $team]]],
            'latestOpinionatedReviews' => ['nodes' => [
                ['author' => ['login' => 'octocat'], 'state' => 'APPROVED', 'submittedAt' => '2026-10-02T09:00:00Z', 'commit' => ['oid' => 'headsha']],
            ]],
        ]),
    ]))]);

    app(SyncRepositoryPullRequests::class)->handle($repository, $account);

    expect($repository->pullRequests()->sole())
        ->is_review_requested_from_me->toBeFalse()
        ->requested_via_teams->toBe([])
        ->requested_reviewers->toBe([])
        ->attention()->toBe(ReviewAttention::Approved);
});

it('keeps a review request made after the user\'s latest review', function () {
    $account = GithubAccount::factory()->create(['login' => 'octocat']);
    $repository = Repository::factory()->create();
    $octocat = ['__typename' => 'User', 'login' => 'octocat'];
    Http::fake(['api.github.com/graphql' => Http::response(githubOpenPullRequestsResponse([
        githubPullRequestNode([
            'reviewRequests' => ['nodes' => [['requestedReviewer' => $octocat]]],
            'reviewRequestedEvents' => ['nodes' => [
                ['createdAt' => '2026-10-01T10:00:00Z', 'requestedReviewer' => $octocat],
                ['createdAt' => '2026-10-02T11:00:00Z', 'requestedReviewer' => $octocat],
            ]],
            'latestOpinionatedReviews' => ['nodes' => [
                ['author' => ['login' => 'octocat'], 'state' => 'APPROVED', 'submittedAt' => '2026-10-02T09:00:00Z', 'commit' => ['oid' => 'oldsha']],
            ]],
        ]),
    ]))]);

    app(SyncRepositoryPullRequests::class)->handle($repository, $account);

    expect($repository->pullRequests()->sole())
        ->is_review_requested_directly->toBeTrue()
        ->attention()->toBe(ReviewAttention::AwaitingMyReview);
});

/**
 * Node fields for a pull request whose review was requested from octocat or the acme/reviewers team on 1 October,
 * which GitHub stopped listing when octocat commented on 2 October.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function commentedOnRequestNode(string $requestedFrom, array $overrides = []): array
{
    $reviewer = $requestedFrom === 'team'
        ? ['__typename' => 'Team', 'slug' => 'reviewers', 'name' => 'Reviewers', 'organization' => ['login' => 'acme']]
        : ['__typename' => 'User', 'login' => 'octocat'];

    return githubPullRequestNode([
        'reviewRequestedEvents' => ['nodes' => [['createdAt' => '2026-10-01T10:00:00Z', 'requestedReviewer' => $reviewer]]],
        'latestReviews' => ['nodes' => [
            ['author' => ['login' => 'octocat'], 'state' => 'COMMENTED', 'submittedAt' => '2026-10-02T09:00:00Z', 'commit' => ['oid' => 'headsha']],
        ]],
        ...$overrides,
    ]);
}

it('counts a comment as the user\'s review, as GitHub does', function () {
    $account = GithubAccount::factory()->create(['login' => 'octocat']);
    $repository = Repository::factory()->create();
    Http::fake(['api.github.com/graphql' => Http::response(githubOpenPullRequestsResponse([commentedOnRequestNode('user')]))]);

    app(SyncRepositoryPullRequests::class)->handle($repository, $account);

    expect($repository->pullRequests()->sole())
        ->is_review_requested_from_me->toBeFalse()
        ->attention()->toBe(ReviewAttention::Commented);
});

it('keeps a pull request awaiting the user\'s review after they comment, when they choose to', function (string $requestedFrom, array $overrides, bool $isDirect, array $viaTeams) {
    app(AppSettings::class)->set(AppSettings::KEEP_AWAITING_AFTER_COMMENTS, true);
    $account = GithubAccount::factory()->inTeam('acme', 'reviewers')->create(['login' => 'octocat']);
    $repository = Repository::factory()->create();
    Http::fake(['api.github.com/graphql' => Http::response(githubOpenPullRequestsResponse([commentedOnRequestNode($requestedFrom, $overrides)]))]);

    app(SyncRepositoryPullRequests::class)->handle($repository, $account);

    expect($repository->pullRequests()->sole())
        ->is_review_requested_from_me->toBeTrue()
        ->is_review_requested_directly->toBe($isDirect)
        ->requested_via_teams->toBe($viaTeams)
        ->requested_reviewers->toBe([])
        ->attention()->toBe(ReviewAttention::AwaitingMyReview);
})->with([
    'requested directly' => ['user', [], true, []],
    'requested through a team' => ['team', [], false, ['acme/reviewers']],
    'requested directly, approved by someone else' => ['user', ['latestOpinionatedReviews' => ['nodes' => [
        ['author' => ['login' => 'hubot'], 'state' => 'APPROVED', 'submittedAt' => '2026-10-03T09:00:00Z', 'commit' => ['oid' => 'headsha']],
    ]]], true, []],
]);

it('still ends a request the user only commented on when it is approved or taken back', function (string $requestedFrom, array $overrides) {
    app(AppSettings::class)->set(AppSettings::KEEP_AWAITING_AFTER_COMMENTS, true);
    $account = GithubAccount::factory()->inTeam('acme', 'reviewers')->create(['login' => 'octocat']);
    $repository = Repository::factory()->create();
    Http::fake(['api.github.com/graphql' => Http::response(githubOpenPullRequestsResponse([commentedOnRequestNode($requestedFrom, $overrides)]))]);

    app(SyncRepositoryPullRequests::class)->handle($repository, $account);

    expect($repository->pullRequests()->sole()->is_review_requested_from_me)->toBeFalse();
})->with([
    'the user approved it' => ['user', ['latestOpinionatedReviews' => ['nodes' => [
        ['author' => ['login' => 'octocat'], 'state' => 'APPROVED', 'submittedAt' => '2026-10-01T12:00:00Z', 'commit' => ['oid' => 'headsha']],
    ]]]],
    'a teammate requested changes' => ['team', ['latestOpinionatedReviews' => ['nodes' => [
        ['author' => ['login' => 'hubot'], 'state' => 'CHANGES_REQUESTED', 'submittedAt' => '2026-10-03T09:00:00Z', 'commit' => ['oid' => 'headsha']],
    ]]]],
    'the author took the request back' => ['user', ['reviewRequestRemovedEvents' => ['nodes' => [
        ['createdAt' => '2026-10-01T11:00:00Z', 'requestedReviewer' => ['__typename' => 'User', 'login' => 'octocat']],
    ]]]],
    'the pull request was merged' => ['user', ['state' => 'MERGED', 'closedAt' => '2026-10-03T09:00:00Z', 'mergedAt' => '2026-10-03T09:00:00Z']],
]);

it('does not treat a pull request kept awaiting after a comment as a new review request', function () {
    app(AppSettings::class)->set(AppSettings::KEEP_AWAITING_AFTER_COMMENTS, true);
    $account = GithubAccount::factory()->create(['login' => 'octocat']);
    $repository = Repository::factory()->create();
    Http::fake(['api.github.com/graphql' => Http::response(githubOpenPullRequestsResponse([commentedOnRequestNode('user')]))]);

    $result = app(SyncRepositoryPullRequests::class)->handle($repository, $account);

    expect($result['newly_requested'])->toBeEmpty()
        ->and($repository->pullRequests()->sole()->is_review_requested_from_me)->toBeTrue();
});

it('wakes a snoozed pull request when new commits are pushed to it', function () {
    $account = GithubAccount::factory()->create(['login' => 'octocat']);
    $repository = Repository::factory()->create();
    $pushedTo = PullRequest::factory()->for($repository)->snoozed()->create(['number' => 1, 'head_sha' => 'oldsha']);
    $unchanged = PullRequest::factory()->for($repository)->snoozed()->create(['number' => 2, 'head_sha' => 'samesha']);
    Http::fake(['api.github.com/graphql' => Http::response(githubOpenPullRequestsResponse([
        githubPullRequestNode(['number' => 1, 'headRefOid' => 'newsha']),
        githubPullRequestNode(['number' => 2, 'headRefOid' => 'samesha']),
    ]))]);

    app(SyncRepositoryPullRequests::class)->handle($repository, $account);

    expect($pushedTo->fresh()->snoozed_until)->toBeNull()
        ->and($unchanged->fresh()->snoozed_until)->not->toBeNull();
});

it('does not count a review requested from the user\'s team on their own pull request', function () {
    $account = GithubAccount::factory()->inTeam('acme', 'reviewers')->create(['login' => 'octocat']);
    $repository = Repository::factory()->create();
    Http::fake(['api.github.com/graphql' => Http::response(githubOpenPullRequestsResponse([
        githubPullRequestNode([
            'author' => ['login' => 'octocat', 'avatarUrl' => null],
            'reviewRequests' => ['nodes' => [
                ['requestedReviewer' => ['__typename' => 'Team', 'slug' => 'reviewers', 'name' => 'Reviewers', 'organization' => ['login' => 'acme']]],
            ]],
        ]),
    ]))]);

    app(SyncRepositoryPullRequests::class)->handle($repository, $account);

    expect($repository->pullRequests()->sole())
        ->is_review_requested_from_me->toBeFalse()
        ->requested_via_teams->toBe([])
        ->attention()->toBe(ReviewAttention::Authored);
    expect(PullRequest::query()->awaitingMyReview()->count())->toBe(0);
});

it('closes pull requests that are no longer open on GitHub, noting which were merged', function () {
    $account = GithubAccount::factory()->create();
    $repository = Repository::factory()->create();
    $merged = PullRequest::factory()->for($repository)->awaitingMyReview()->create(['number' => 98, 'node_id' => 'PR_merged']);
    $closed = PullRequest::factory()->for($repository)->create(['number' => 99, 'node_id' => 'PR_closed']);
    Http::fake(['api.github.com/graphql' => Http::sequence()
        ->push(githubOpenPullRequestsResponse([]))
        ->push(['data' => ['nodes' => [
            ['id' => 'PR_merged', 'state' => 'MERGED', 'closedAt' => '2026-10-20T10:00:00Z', 'mergedAt' => '2026-10-20T10:00:00Z'],
            ['id' => 'PR_closed', 'state' => 'CLOSED', 'closedAt' => '2026-10-21T10:00:00Z', 'mergedAt' => null],
        ]]])]);

    app(SyncRepositoryPullRequests::class)->handle($repository, $account);

    expect($merged->fresh())
        ->state->toBe(PullRequest::STATE_CLOSED)
        ->is_review_requested_from_me->toBeFalse()
        ->closed_at->toDateTimeString()->toBe('2026-10-20 10:00:00')
        ->merged_at->toDateTimeString()->toBe('2026-10-20 10:00:00');
    expect($closed->fresh())
        ->state->toBe(PullRequest::STATE_CLOSED)
        ->closed_at->toDateTimeString()->toBe('2026-10-21 10:00:00')
        ->merged_at->toBeNull();
    Http::assertSent(fn (Request $request): bool => str_contains($request['query'], 'nodes(ids:')
        && $request['variables']['ids'] === ['PR_merged', 'PR_closed']);
});

it('keeps a pull request open when GitHub still reports it as open', function () {
    $account = GithubAccount::factory()->create();
    $repository = Repository::factory()->create();
    $pullRequest = PullRequest::factory()->for($repository)->awaitingMyReview()->create(['node_id' => 'PR_still_open']);
    Http::fake(['api.github.com/graphql' => Http::sequence()
        ->push(githubOpenPullRequestsResponse([]))
        ->push(['data' => ['nodes' => [['id' => 'PR_still_open', 'state' => 'OPEN', 'closedAt' => null, 'mergedAt' => null]]]])]);

    app(SyncRepositoryPullRequests::class)->handle($repository, $account);

    expect($pullRequest->fresh())
        ->state->toBe(PullRequest::STATE_OPEN)
        ->is_review_requested_from_me->toBeTrue();
});

it('closes a pull request GitHub can no longer find', function () {
    $account = GithubAccount::factory()->create();
    $repository = Repository::factory()->create();
    $pullRequest = PullRequest::factory()->for($repository)->create(['node_id' => 'PR_gone']);
    Http::fake(['api.github.com/graphql' => Http::sequence()
        ->push(githubOpenPullRequestsResponse([]))
        ->push([
            'data' => ['nodes' => [null]],
            'errors' => [['type' => 'NOT_FOUND', 'path' => ['nodes', 0], 'message' => "Could not resolve to a node with the global id of 'PR_gone'."]],
        ])]);

    app(SyncRepositoryPullRequests::class)->handle($repository, $account);

    expect($pullRequest->fresh())
        ->state->toBe(PullRequest::STATE_CLOSED)
        ->closed_at->not->toBeNull()
        ->merged_at->toBeNull();
});

it('still closes pull requests when GitHub cannot say whether they were merged', function () {
    $account = GithubAccount::factory()->create();
    $repository = Repository::factory()->create();
    $pullRequest = PullRequest::factory()->for($repository)->awaitingMyReview()->create();
    Http::fake(['api.github.com/graphql' => Http::sequence()
        ->push(githubOpenPullRequestsResponse([]))
        ->push(['errors' => [['type' => 'RATE_LIMITED', 'message' => 'API rate limit exceeded.']]])]);

    app(SyncRepositoryPullRequests::class)->handle($repository, $account);

    expect($pullRequest->fresh())
        ->state->toBe(PullRequest::STATE_CLOSED)
        ->is_review_requested_from_me->toBeFalse()
        ->closed_at->not->toBeNull()
        ->merged_at->toBeNull();
});

it('returns only pull requests whose review was newly requested from the user', function () {
    $account = GithubAccount::factory()->create(['login' => 'octocat']);
    $repository = Repository::factory()->create();
    PullRequest::factory()->for($repository)->awaitingMyReview()->create(['number' => 1]);
    $requested = ['reviewRequests' => ['nodes' => [['requestedReviewer' => ['__typename' => 'User', 'login' => 'octocat']]]]];
    Http::fake(['api.github.com/graphql' => Http::response(githubOpenPullRequestsResponse([
        githubPullRequestNode(['number' => 1, ...$requested]),
        githubPullRequestNode(['number' => 2, ...$requested]),
    ]))]);

    $result = app(SyncRepositoryPullRequests::class)->handle($repository, $account);

    expect($result['newly_requested']->pluck('number')->all())->toBe([2]);
});

it('does not count a closed pull request as awaiting review even if GitHub still lists the request', function () {
    $account = GithubAccount::factory()->create(['login' => 'octocat']);
    $repository = Repository::factory()->create();
    Http::fake(['api.github.com/graphql' => Http::response(githubOpenPullRequestsResponse([
        githubPullRequestNode(['state' => 'MERGED', 'closedAt' => '2026-10-20T10:00:00Z', 'mergedAt' => '2026-10-20T10:00:00Z', 'reviewRequests' => ['nodes' => [
            ['requestedReviewer' => ['__typename' => 'User', 'login' => 'octocat']],
        ]]]),
    ]))]);

    app(SyncRepositoryPullRequests::class)->handle($repository, $account);

    expect($repository->pullRequests()->sole())
        ->state->toBe(PullRequest::STATE_CLOSED)
        ->merged_at->not->toBeNull()
        ->is_review_requested_from_me->toBeFalse();
});

it('reports pull requests opened by someone else since the last sync', function () {
    $account = GithubAccount::factory()->create(['login' => 'octocat']);
    $repository = Repository::factory()->create(['synced_at' => now()->subMinutes(5)]);
    PullRequest::factory()->for($repository)->create(['number' => 1]);
    Http::fake(['api.github.com/graphql' => Http::response(githubOpenPullRequestsResponse([
        githubPullRequestNode(['number' => 1]),
        githubPullRequestNode(['number' => 2]),
        githubPullRequestNode(['number' => 3, 'author' => ['login' => 'octocat', 'avatarUrl' => null]]),
    ]))]);

    $result = app(SyncRepositoryPullRequests::class)->handle($repository, $account);

    expect($result['newly_opened']->pluck('number')->all())->toBe([2]);
});

it('does not report every existing pull request as new on a repository\'s first sync', function () {
    $account = GithubAccount::factory()->create();
    $repository = Repository::factory()->create(['synced_at' => null]);
    Http::fake(['api.github.com/graphql' => Http::response(githubOpenPullRequestsResponse([githubPullRequestNode()]))]);

    $result = app(SyncRepositoryPullRequests::class)->handle($repository, $account);

    expect($result['newly_opened'])->toBeEmpty();
});
