<?php

use App\Jobs\SyncGithub;
use App\Jobs\SyncRepository;
use App\Models\GithubAccount;
use App\Models\Repository;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake([SyncRepository::class]);
    Exceptions::fake();
});

it('refreshes the account and syncs every tracked repository', function () {
    $account = GithubAccount::factory()->create(['login' => 'octocat']);
    $web = trackedRepository('acme/web');
    $api = trackedRepository('acme/api');
    Repository::factory()->untracked()->create();
    Http::fake([
        'api.github.com/user/teams*' => Http::response([['slug' => 'reviewers', 'name' => 'Reviewers', 'organization' => ['login' => 'acme']]]),
        'api.github.com/search/issues*' => Http::response(['items' => []]),
    ]);

    SyncGithub::dispatchSync();

    expect($account->refresh()->isMemberOfTeam('acme', 'reviewers'))->toBeTrue();
    Queue::assertPushed(SyncRepository::class, 2);
    Queue::assertPushed(SyncRepository::class, fn (SyncRepository $job): bool => $job->repository->is($web));
    Queue::assertPushed(SyncRepository::class, fn (SyncRepository $job): bool => $job->repository->is($api));
    Exceptions::assertNothingReported();
});

it('does nothing before GitHub is connected', function () {
    trackedRepository('acme/web');

    SyncGithub::dispatchSync();

    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('stops quietly when GitHub no longer accepts the token', function () {
    $account = GithubAccount::factory()->inTeam('acme', 'reviewers')->create();
    trackedRepository('acme/web');
    Http::fake(['api.github.com/*' => Http::response(['message' => 'Bad credentials'], 401)]);

    SyncGithub::dispatchSync();

    expect($account->refresh()->isMemberOfTeam('acme', 'reviewers'))->toBeTrue();
    Queue::assertNothingPushed();
    Exceptions::assertNothingReported();
});

it('still syncs tracked repositories when refreshing the account fails', function () {
    $account = GithubAccount::factory()->inTeam('acme', 'reviewers')->create(['untracked_review_requests' => ['acme/mobile']]);
    trackedRepository('acme/web');
    Http::fake([
        'api.github.com/user/teams*' => Http::response([]),
        'api.github.com/search/issues*' => Http::response(['message' => 'API rate limit exceeded'], 403),
    ]);

    SyncGithub::dispatchSync();

    expect($account->refresh())
        ->isMemberOfTeam('acme', 'reviewers')->toBeTrue()
        ->untracked_review_requests->toBe(['acme/mobile']);
    Queue::assertPushed(SyncRepository::class, 1);
    Exceptions::assertReported(fn (RequestException $exception): bool => $exception->response->status() === 403);
});
