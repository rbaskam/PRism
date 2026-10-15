<?php

use App\Models\GithubNotification;
use App\Models\PullRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('opens a pull request notification in the app', function () {
    onboardedAccount();
    $pullRequest = PullRequest::factory()->create(['number' => 8]);
    $notification = GithubNotification::factory()->create([
        'pull_request_id' => null,
        'repository_full_name' => $pullRequest->repository->full_name,
        'subject_url' => "https://api.github.com/repos/{$pullRequest->repository->full_name}/pulls/8",
    ]);

    $response = $this->get(route('inbox.open', $notification));

    $response->assertRedirect(route('pull-requests.show', $pullRequest));
});

it('marks a notification as read on GitHub without sending a body', function () {
    Http::preventStrayRequests();
    onboardedAccount();
    $notification = GithubNotification::factory()->create(['github_id' => '26334037206', 'is_unread' => true]);
    Http::fake(['api.github.com/notifications/threads/26334037206' => Http::response(null, 205)]);

    $this->post(route('inbox.read', $notification))->assertSessionMissing('error');

    expect($notification->fresh()->is_unread)->toBeFalse();
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH' && $request->body() === '');
});
