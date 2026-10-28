<?php

use App\Actions\PollGithubNotifications;
use App\Enums\AlertType;
use App\Jobs\SyncRepository;
use App\Models\Alert;
use App\Models\GithubAccount;
use App\Models\GithubNotification;
use App\Models\PullRequest;
use App\Models\Repository;
use App\Services\AppSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * @return array<string, mixed>
 */
function githubNotificationThread(string $id, string $reason, int $number = 12, string $updatedAt = '2026-10-13T09:00:00Z'): array
{
    return [
        'id' => $id,
        'reason' => $reason,
        'unread' => true,
        'updated_at' => $updatedAt,
        'subject' => [
            'title' => 'Add rate limiting',
            'url' => "https://api.github.com/repos/acme/app/pulls/{$number}",
            'type' => 'PullRequest',
        ],
        'repository' => ['full_name' => 'acme/app', 'html_url' => 'https://github.com/acme/app'],
    ];
}

beforeEach(function () {
    Http::preventStrayRequests();
    config(['nativephp-internal.running' => true]);
});

it('sends a desktop notification when a review is requested', function () {
    Queue::fake([SyncRepository::class]);
    $account = GithubAccount::factory()->create(['notifications_polled_at' => now()->subMinute()]);
    $repository = Repository::factory()->create(['full_name' => 'acme/app']);
    $pullRequest = PullRequest::factory()->for($repository)->create(['number' => 12]);
    Http::fake([
        'api.github.com/notifications*' => Http::response([githubNotificationThread('100', 'review_requested')], 200, ['Last-Modified' => 'Tue, 13 Oct 2026 09:00:00 GMT']),
        '*/api/notification' => Http::response(['reference' => 'x']),
    ]);

    app(PollGithubNotifications::class)->handle($account);

    $notification = GithubNotification::sole();
    expect($notification)
        ->pull_request_id->toBe($pullRequest->id)
        ->html_url->toBe('https://github.com/acme/app/pull/12')
        ->desktop_notified_at->not->toBeNull();
    expect($account->fresh()->notifications_last_modified)->toBe('Tue, 13 Oct 2026 09:00:00 GMT');
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/notification')
        && $request['title'] === 'Review requested'
        && $request['reference'] === "github-notification:{$notification->id}");
    expect(Alert::sole())
        ->type->toBe(AlertType::ReviewRequests)
        ->pull_request_id->toBe($pullRequest->id)
        ->url->toBe("/pull-requests/{$pullRequest->id}");
    Queue::assertPushed(SyncRepository::class, fn (SyncRepository $job): bool => $job->repository->is($repository));
});

it('does not alert about old notifications on the first poll', function () {
    $account = GithubAccount::factory()->create(['notifications_polled_at' => null]);
    Http::fake([
        'api.github.com/notifications*' => Http::response([githubNotificationThread('100', 'mention')]),
    ]);

    app(PollGithubNotifications::class)->handle($account);

    expect(GithubNotification::sole()->desktop_notified_at)->not->toBeNull();
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/notification'));
});

it('does not alert about mentions when mention alerts are turned off', function () {
    app(AppSettings::class)->set('notifications.'.AlertType::Mentions->value, false);
    $account = GithubAccount::factory()->create(['notifications_polled_at' => now()->subMinute()]);
    Http::fake([
        'api.github.com/notifications*' => Http::response([githubNotificationThread('100', 'team_mention')]),
    ]);

    app(PollGithubNotifications::class)->handle($account);

    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/notification'));
});

it('does not alert twice about the same notification', function () {
    $account = GithubAccount::factory()->create(['notifications_polled_at' => now()->subMinute()]);
    GithubNotification::factory()->create([
        'github_id' => '100',
        'reason' => 'mention',
        'github_updated_at' => '2026-10-13 09:00:00',
        'desktop_notified_at' => '2026-10-13 09:00:30',
    ]);
    Http::fake([
        'api.github.com/notifications*' => Http::response([githubNotificationThread('100', 'mention')]),
    ]);

    app(PollGithubNotifications::class)->handle($account);

    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/notification'));
});

it('does nothing when GitHub reports no new notifications since the last poll', function () {
    $account = GithubAccount::factory()->create([
        'notifications_polled_at' => now()->subMinute(),
        'notifications_last_modified' => 'Tue, 13 Oct 2026 09:00:00 GMT',
    ]);
    Http::fake(['api.github.com/notifications*' => Http::response(null, 304)]);

    app(PollGithubNotifications::class)->handle($account);

    expect(GithubNotification::count())->toBe(0)
        ->and($account->fresh()->notifications_last_modified)->toBe('Tue, 13 Oct 2026 09:00:00 GMT');
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('If-Modified-Since', 'Tue, 13 Oct 2026 09:00:00 GMT'));
});

it('alerts about a review request once, not on every later update to the pull request', function () {
    Queue::fake([SyncRepository::class]);
    $account = GithubAccount::factory()->create(['notifications_polled_at' => now()->subMinute()]);
    GithubNotification::factory()->create([
        'github_id' => '100',
        'reason' => 'review_requested',
        'github_updated_at' => '2026-10-13 09:00:00',
        'desktop_notified_at' => '2026-10-13 09:00:30',
    ]);
    Http::fake(['api.github.com/notifications*' => Http::response([githubNotificationThread('100', 'review_requested', updatedAt: '2026-10-13T10:00:00Z')])]);

    app(PollGithubNotifications::class)->handle($account);

    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/notification'));
});

it('alerts about later activity on a review request when the user asks for it', function () {
    Queue::fake([SyncRepository::class]);
    app(AppSettings::class)->set(AppSettings::NOTIFY_FOLLOW_UP_ACTIVITY, true);
    $account = GithubAccount::factory()->create(['notifications_polled_at' => now()->subMinute()]);
    GithubNotification::factory()->create([
        'github_id' => '100',
        'reason' => 'review_requested',
        'github_updated_at' => '2026-10-13 09:00:00',
        'desktop_notified_at' => '2026-10-13 09:00:30',
    ]);
    Http::fake([
        'api.github.com/notifications*' => Http::response([githubNotificationThread('100', 'review_requested', updatedAt: '2026-10-13T10:00:00Z')]),
        '*/api/notification' => Http::response(['reference' => 'x']),
    ]);

    app(PollGithubNotifications::class)->handle($account);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/notification'));
});

it('alerts about each new comment on the user\'s own pull request', function () {
    $account = GithubAccount::factory()->create(['notifications_polled_at' => now()->subMinute()]);
    GithubNotification::factory()->create([
        'github_id' => '200',
        'reason' => 'author',
        'github_updated_at' => '2026-10-13 09:00:00',
        'desktop_notified_at' => '2026-10-13 09:00:30',
    ]);
    Http::fake([
        'api.github.com/notifications*' => Http::response([githubNotificationThread('200', 'author', updatedAt: '2026-10-13T10:00:00Z')]),
        '*/api/notification' => Http::response(['reference' => 'x']),
    ]);

    app(PollGithubNotifications::class)->handle($account);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/notification')
        && $request['title'] === 'New activity on your pull request');
});

it('does not alert about CI results unless switched on', function () {
    $account = GithubAccount::factory()->create(['notifications_polled_at' => now()->subMinute()]);
    Http::fake(['api.github.com/notifications*' => Http::response([githubNotificationThread('300', 'ci_activity')])]);

    app(PollGithubNotifications::class)->handle($account);

    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/notification'));
});

it('closes a pull request merged on GitHub as soon as its notification updates, without waiting for the next full sync', function () {
    $account = GithubAccount::factory()->create(['notifications_polled_at' => now()->subMinute()]);
    $repository = trackedRepository('acme/app');
    $pullRequest = PullRequest::factory()->for($repository)->awaitingMyReview()->create(['number' => 12]);
    GithubNotification::factory()->create([
        'github_id' => '100',
        'github_updated_at' => '2026-10-13 09:00:00',
        'desktop_notified_at' => '2026-10-13 09:00:00',
    ]);
    Http::fake([
        'api.github.com/notifications*' => Http::response([githubNotificationThread('100', 'review_requested', 12, '2026-10-14T09:00:00Z')]),
        'api.github.com/graphql' => Http::sequence()
            ->push(githubOpenPullRequestsResponse([]))
            ->push(['data' => ['nodes' => [
                ['id' => $pullRequest->node_id, 'state' => 'MERGED', 'closedAt' => '2026-10-14T09:00:00Z', 'mergedAt' => '2026-10-14T09:00:00Z'],
            ]]]),
        '*/api/*' => Http::response(),
    ]);

    app(PollGithubNotifications::class)->handle($account);

    expect($pullRequest->fresh())
        ->state->toBe(PullRequest::STATE_CLOSED)
        ->closed_at->not->toBeNull()
        ->merged_at->not->toBeNull();
});

it('leaves repositories alone when nothing changed on their pull requests', function () {
    Queue::fake([SyncRepository::class]);
    $account = GithubAccount::factory()->create(['notifications_polled_at' => now()->subMinute()]);
    trackedRepository('acme/app');
    GithubNotification::factory()->create(['github_id' => '100', 'github_updated_at' => '2026-10-13 09:00:00']);
    Http::fake([
        'api.github.com/notifications*' => Http::response([githubNotificationThread('100', 'review_requested', 12, '2026-10-13T09:00:00Z')]),
    ]);

    app(PollGithubNotifications::class)->handle($account);

    Queue::assertNotPushed(SyncRepository::class);
});

it('does not sync a repository for activity on its issues', function () {
    Queue::fake([SyncRepository::class]);
    $account = GithubAccount::factory()->create(['notifications_polled_at' => now()->subMinute()]);
    trackedRepository('acme/app');
    $issue = githubNotificationThread('200', 'mention');
    $issue['subject'] = ['title' => 'Login page is slow', 'url' => 'https://api.github.com/repos/acme/app/issues/7', 'type' => 'Issue'];
    Http::fake([
        'api.github.com/notifications*' => Http::response([$issue]),
        '*/api/*' => Http::response(['reference' => 'x']),
    ]);

    app(PollGithubNotifications::class)->handle($account);

    Queue::assertNotPushed(SyncRepository::class);
});

it('opens alerts about comments on the pull request\'s conversation', function () {
    $account = GithubAccount::factory()->create(['notifications_polled_at' => now()->subMinute()]);
    $repository = trackedRepository('acme/app');
    $pullRequest = PullRequest::factory()->for($repository)->authoredByMe()->create(['number' => 12]);
    Http::fake([
        'api.github.com/notifications*' => Http::response([githubNotificationThread('300', 'author')]),
        '*/api/*' => Http::response(['reference' => 'x']),
        'api.github.com/graphql' => Http::response(githubOpenPullRequestsResponse([])),
    ]);

    app(PollGithubNotifications::class)->handle($account);

    expect(Alert::sole()->url)->toBe("/pull-requests/{$pullRequest->id}?tab=conversation");
});

it('keeps alerts for the bell when desktop notifications are off', function () {
    app(AppSettings::class)->set(AppSettings::NOTIFY_DESKTOP, false);
    $account = GithubAccount::factory()->create(['notifications_polled_at' => now()->subMinute()]);
    Http::fake([
        'api.github.com/notifications*' => Http::response([githubNotificationThread('400', 'mention')]),
    ]);

    app(PollGithubNotifications::class)->handle($account);

    expect(Alert::sole()->title)->toBe('You were mentioned');
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/notification'));
});
