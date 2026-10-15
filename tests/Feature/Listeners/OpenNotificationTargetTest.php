<?php

use App\Models\GithubAccount;
use App\Models\GithubNotification;
use App\Models\PullRequest;
use Illuminate\Support\Facades\Http;
use Native\Desktop\Events\Notifications\NotificationClicked;
use Native\Desktop\Facades\Shell;
use Native\Desktop\Facades\Window;
use Native\Desktop\Windows\Window as NativeWindow;

it('opens the pull request in the app when a review request notification is clicked', function () {
    Http::fake(['*/api/window/*' => Http::response()]);
    $windows = Window::fake()->alwaysReturnWindows([new NativeWindow('main')]);
    $pullRequest = PullRequest::factory()->create();
    $notification = GithubNotification::factory()->create(['pull_request_id' => $pullRequest->id]);

    event(new NotificationClicked("github-notification:{$notification->id}", '{}'));

    $windows->assertOpened('main');
});

it('opens GitHub in the browser when the notification is not about a pull request', function () {
    $shell = Shell::fake();
    $notification = GithubNotification::factory()->create([
        'pull_request_id' => null,
        'subject_type' => 'Issue',
        'subject_url' => 'https://api.github.com/repos/acme/other/issues/3',
        'html_url' => 'https://github.com/acme/other/issues/3',
    ]);

    event(new NotificationClicked("github-notification:{$notification->id}", '{}'));

    $shell->assertOpenedExternal('https://github.com/acme/other/issues/3');
});

it('opens a review request in the app even before its notification was linked to the pull request', function () {
    Http::fake(['*/api/window/*' => Http::response()]);
    $windows = Window::fake()->alwaysReturnWindows([new NativeWindow('main')]);
    GithubAccount::factory()->create();
    $pullRequest = PullRequest::factory()->create(['number' => 12]);
    $notification = GithubNotification::factory()->create([
        'pull_request_id' => null,
        'repository_full_name' => $pullRequest->repository->full_name,
        'subject_url' => "https://api.github.com/repos/{$pullRequest->repository->full_name}/pulls/12",
    ]);

    event(new NotificationClicked("github-notification:{$notification->id}", '{}'));

    $windows->assertOpened('main');
    expect($notification->fresh()->pull_request_id)->toBe($pullRequest->id);
});
