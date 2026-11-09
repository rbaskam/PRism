<?php

use App\Models\GithubNotification;
use App\Models\PullRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Native\Desktop\Facades\Shell;

beforeEach(function () {
    Http::preventStrayRequests();
    onboardedAccount(['login' => 'octocat']);
});

it('lists notifications most recently updated first', function () {
    $this->freezeTime();
    $pullRequest = PullRequest::factory()->create();
    $older = GithubNotification::factory()->create(['github_updated_at' => now()->subDay()]);
    $newest = GithubNotification::factory()->create([
        'reason' => 'mention',
        'subject_type' => 'PullRequest',
        'subject_title' => 'Add search to the docs',
        'html_url' => 'https://github.com/acme/docs/pull/7',
        'repository_full_name' => 'acme/docs',
        'pull_request_id' => $pullRequest->id,
        'is_unread' => false,
        'github_updated_at' => now(),
    ]);
    $sameTimeAddedFirst = GithubNotification::factory()->create(['github_updated_at' => now()->subHour()]);
    $sameTimeAddedLast = GithubNotification::factory()->create(['github_updated_at' => now()->subHour()]);

    $response = $this->get(route('inbox.index'));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('inbox/Index')
        ->has('notifications', 4)
        ->has('notifications.0', fn (Assert $notification) => $notification
            ->where('id', $newest->id)
            ->where('reason', 'mention')
            ->where('subject_type', 'PullRequest')
            ->where('subject_title', 'Add search to the docs')
            ->where('html_url', 'https://github.com/acme/docs/pull/7')
            ->where('repository_full_name', 'acme/docs')
            ->where('pull_request_id', $pullRequest->id)
            ->where('is_unread', false)
            ->where('updated_at', now()->toIso8601String()))
        ->where('notifications.1.id', $sameTimeAddedLast->id)
        ->where('notifications.2.id', $sameTimeAddedFirst->id)
        ->where('notifications.3.id', $older->id));
});

it('shows an empty inbox when there are no notifications', function () {
    $response = $this->get(route('inbox.index'));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('inbox/Index')
        ->where('notifications', []));
});

it('lists only the 200 most recent notifications', function () {
    $oldest = GithubNotification::factory()->create(['github_updated_at' => now()->subYear()]);
    GithubNotification::factory()->count(200)->create();

    $response = $this->get(route('inbox.index'));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('notifications', 200)
        ->where('notifications', fn ($notifications): bool => collect($notifications)->pluck('id')->doesntContain($oldest->id)));
});

it('opens a notification that is not about a pull request on GitHub', function () {
    $notification = GithubNotification::factory()->create([
        'subject_type' => 'Issue',
        'subject_url' => 'https://api.github.com/repos/acme/app/issues/3',
        'html_url' => 'https://github.com/acme/app/issues/3',
    ]);

    $response = $this->get(route('inbox.open', $notification));

    $response->assertRedirect('https://github.com/acme/app/issues/3');
    Http::assertNothingSent();
});

it('opens a notification in the browser from the desktop app and stays on the inbox', function () {
    config(['nativephp-internal.running' => true]);
    Shell::fake();
    $notification = GithubNotification::factory()->create([
        'subject_type' => 'Release',
        'subject_url' => null,
        'html_url' => 'https://github.com/acme/app/releases/tag/v1.0.0',
    ]);

    $response = $this->from(route('inbox.index'))->get(route('inbox.open', $notification));

    $response->assertRedirect(route('inbox.index'));
    Shell::assertOpenedExternal('https://github.com/acme/app/releases/tag/v1.0.0');
});

it('opens a pull request notification on GitHub when the pull request cannot be fetched', function () {
    $notification = GithubNotification::factory()->create([
        'pull_request_id' => null,
        'repository_full_name' => 'acme/private',
        'subject_url' => 'https://api.github.com/repos/acme/private/pulls/9',
        'html_url' => 'https://github.com/acme/private/pull/9',
    ]);
    Http::fake(['api.github.com/repos/acme/private' => Http::response(['message' => 'Not Found'], 404)]);

    $response = $this->get(route('inbox.open', $notification));

    $response->assertRedirect('https://github.com/acme/private/pull/9');
    expect(PullRequest::count())->toBe(0)
        ->and($notification->fresh()->pull_request_id)->toBeNull();
});

it('explains when a notification does not link anywhere', function () {
    $notification = GithubNotification::factory()->create([
        'subject_type' => 'CheckSuite',
        'subject_url' => null,
        'html_url' => null,
    ]);

    $response = $this->from(route('inbox.index'))->get(route('inbox.open', $notification));

    $response->assertRedirect(route('inbox.index'))
        ->assertSessionHas('error', "This notification doesn't link anywhere.");
});

it('keeps a notification unread when GitHub fails to mark it as read', function (Closure $githubResponse, string $error) {
    $notification = GithubNotification::factory()->create(['github_id' => '1001', 'is_unread' => true]);
    Http::fake(['api.github.com/notifications/threads/1001' => $githubResponse]);

    $response = $this->from(route('inbox.index'))->post(route('inbox.read', $notification));

    $response->assertRedirect(route('inbox.index'))->assertSessionHas('error', $error);
    expect($notification->fresh()->is_unread)->toBeTrue();
})->with([
    'GitHub refuses' => [fn () => Http::response(['message' => 'Not Found'], 404), 'GitHub returned 404: Not Found'],
    'GitHub cannot be reached' => [fn () => Http::failedConnection(), 'Could not reach GitHub. Check your internet connection.'],
]);

it('marks every notification as read on GitHub and in the app', function () {
    $unread = GithubNotification::factory()->count(2)->create(['is_unread' => true]);
    Http::fake(['api.github.com/notifications' => Http::response(null, 205)]);

    $response = $this->from(route('inbox.index'))->post(route('inbox.read-all'));

    $response->assertRedirect(route('inbox.index'))
        ->assertSessionHas('success', 'All notifications marked as read.');
    expect(GithubNotification::where('is_unread', true)->count())->toBe(0);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->url() === 'https://api.github.com/notifications'
        && $request['read'] === true);
});

it('keeps every notification unread when GitHub fails to mark them as read', function () {
    GithubNotification::factory()->count(2)->create(['is_unread' => true]);
    Http::fake(['api.github.com/notifications' => Http::response(['message' => 'Requires authentication'], 401)]);

    $response = $this->from(route('inbox.index'))->post(route('inbox.read-all'));

    $response->assertRedirect(route('inbox.index'))
        ->assertSessionHas('error', 'GitHub returned 401: Requires authentication')
        ->assertSessionMissing('success');
    expect(GithubNotification::where('is_unread', true)->count())->toBe(2);
});
