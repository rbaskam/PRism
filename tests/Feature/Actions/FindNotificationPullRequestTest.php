<?php

use App\Actions\FindNotificationPullRequest;
use App\Models\GithubAccount;
use App\Models\GithubNotification;
use App\Models\Repository;
use Illuminate\Support\Facades\Http;

it('fetches a pull request PRism has not synced yet, even from a repository the user does not track', function () {
    Http::preventStrayRequests();
    GithubAccount::factory()->create(['login' => 'octocat']);
    $notification = GithubNotification::factory()->create([
        'pull_request_id' => null,
        'repository_full_name' => 'acme/web',
        'subject_url' => 'https://api.github.com/repos/acme/web/pulls/5',
    ]);
    Http::fake([
        'api.github.com/repos/acme/web' => Http::response([
            'id' => 99, 'name' => 'web', 'full_name' => 'acme/web', 'owner' => ['login' => 'acme'],
            'html_url' => 'https://github.com/acme/web', 'default_branch' => 'main', 'private' => true,
        ]),
        'api.github.com/graphql' => Http::response(['data' => ['repository' => ['pullRequest' => githubPullRequestNode(['number' => 5, 'title' => 'Add search'])]]]),
    ]);

    $pullRequest = app(FindNotificationPullRequest::class)->handle($notification);

    expect($pullRequest)
        ->number->toBe(5)
        ->title->toBe('Add search')
        ->and($pullRequest->repository->is_tracked)->toBeFalse()
        ->and($notification->fresh()->pull_request_id)->toBe($pullRequest->id);
});

it('returns nothing for notifications that are not about a pull request', function () {
    Http::preventStrayRequests();
    GithubAccount::factory()->create();
    $notification = GithubNotification::factory()->create([
        'subject_type' => 'Issue',
        'subject_url' => 'https://api.github.com/repos/acme/web/issues/5',
    ]);

    expect(app(FindNotificationPullRequest::class)->handle($notification))->toBeNull();
    expect(Repository::count())->toBe(0);
});
