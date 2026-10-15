<?php

use App\Enums\AlertType;
use App\Enums\ClaudeAuthMode;
use App\Jobs\RunPullRequestReview;
use App\Jobs\SyncRepository;
use App\Models\GithubAccount;
use App\Models\Repository;
use App\Models\ReviewRun;
use App\Services\AppSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('starts an AI review when the user is newly asked to review and auto review is on', function () {
    Http::preventStrayRequests();
    Queue::fake([RunPullRequestReview::class]);
    GithubAccount::factory()->create(['login' => 'octocat']);
    $settings = app(AppSettings::class);
    $settings->set(AppSettings::AUTO_REVIEW_ON_REQUEST, true);
    $settings->set(AppSettings::CLAUDE_AUTH_MODE, ClaudeAuthMode::CliLogin->value);
    $repository = Repository::factory()->create();
    $requested = ['reviewRequests' => ['nodes' => [['requestedReviewer' => ['__typename' => 'User', 'login' => 'octocat']]]]];
    Http::fake(['api.github.com/graphql' => Http::response(githubOpenPullRequestsResponse([
        githubPullRequestNode(['number' => 1, ...$requested]),
        githubPullRequestNode(['number' => 2, 'isDraft' => true, ...$requested]),
    ]))]);

    SyncRepository::dispatchSync($repository);

    expect(ReviewRun::sole()->pullRequest->number)->toBe(1);
    Queue::assertPushed(RunPullRequestReview::class, 1);
});

it('does not start AI reviews when auto review is off', function () {
    Http::preventStrayRequests();
    Queue::fake([RunPullRequestReview::class]);
    GithubAccount::factory()->create(['login' => 'octocat']);
    app(AppSettings::class)->set(AppSettings::CLAUDE_AUTH_MODE, ClaudeAuthMode::CliLogin->value);
    $repository = Repository::factory()->create();
    Http::fake(['api.github.com/graphql' => Http::response(githubOpenPullRequestsResponse([
        githubPullRequestNode(['reviewRequests' => ['nodes' => [['requestedReviewer' => ['__typename' => 'User', 'login' => 'octocat']]]]]),
    ]))]);

    SyncRepository::dispatchSync($repository);

    expect(ReviewRun::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('sends a desktop alert for a new pull request when that alert is switched on', function () {
    Http::preventStrayRequests();
    config(['nativephp-internal.running' => true]);
    GithubAccount::factory()->create(['login' => 'octocat']);
    app(AppSettings::class)->set('notifications.'.AlertType::NewPullRequests->value, true);
    $repository = Repository::factory()->create(['synced_at' => now()->subMinutes(5)]);
    Http::fake([
        'api.github.com/graphql' => Http::response(githubOpenPullRequestsResponse([githubPullRequestNode(['number' => 7, 'title' => 'Add dark mode'])])),
        '*/api/notification' => Http::response(['reference' => 'x']),
        '*/api/dock/*' => Http::response(),
    ]);

    SyncRepository::dispatchSync($repository);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/notification')
        && $request['title'] === 'New pull request'
        && str_contains($request['body'] ?? $request['message'] ?? '', 'Add dark mode'));
});
