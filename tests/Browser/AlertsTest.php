<?php

use App\Enums\AlertType;
use App\Models\Alert;
use App\Models\PullRequest;
use App\Services\AppSettings;
use Tests\Browser\Support\FakeGithub;

beforeEach(function () {
    onboardedAccount(['login' => 'octocat']);
    trackedRepository('acme/api');
    $this->github = (new FakeGithub)
        ->withPullRequests('acme/api', [
            FakeGithub::pullRequest(101, 'Add rate limiting', [
                ...FakeGithub::requestedFrom('octocat'),
                'conversation' => ['nodes' => [FakeGithub::comment('hubot', 'Should the limit be configurable?')]],
            ]),
        ])
        ->fake();
    syncFromGithub();
});

it('lists alerts under the bell and opens one where it happened', function () {
    $pullRequest = PullRequest::firstWhere('number', 101);
    $alert = Alert::factory()->create([
        'type' => AlertType::MyPullRequests,
        'title' => 'New activity on your pull request',
        'body' => 'acme/api: Add rate limiting',
        'url' => "/pull-requests/{$pullRequest->id}?tab=conversation",
    ]);

    visit('/')
        ->assertPresent('button[aria-label="Notifications, 1 unread"]')
        ->click('button[aria-label="Notifications, 1 unread"]')
        ->waitForText('New activity on your pull request')
        ->assertSee('acme/api: Add rate limiting')
        ->click('[role="dialog"] li:has-text("New activity on your pull request") button')
        ->waitForText('Should the limit be configurable?')
        ->assertPathIs("/pull-requests/{$pullRequest->id}")
        ->assertPresent('[role="tab"][data-state="active"]:has-text("Conversation")')
        ->assertPresent('button[aria-label="Notifications"]')
        ->assertNoJavaScriptErrors();

    expect($alert->fresh()->read_at)->not->toBeNull();
});

it('marks every alert as read', function () {
    Alert::factory()->count(2)->create();

    $page = visit('/');
    trackRequests($page);
    $page->click('button[aria-label="Notifications, 2 unread"]')
        ->click('internal:role=button[name="Mark all as read"i]');
    waitForRequests($page);

    $page->navigate('/')
        ->assertPresent('button[aria-label="Notifications"]')
        ->assertNoJavaScriptErrors();

    expect(Alert::query()->unread()->count())->toBe(0);
});

it('shows a new pull request found by a sync under the bell', function () {
    app(AppSettings::class)->set('notifications.'.AlertType::NewPullRequests->value, true);
    $this->github->withPullRequests('acme/api', [FakeGithub::pullRequest(102, 'Fix login redirect')]);
    syncFromGithub();

    visit('/')
        ->click('button[aria-label="Notifications, 1 unread"]')
        ->waitForText('New pull request')
        ->assertSee('acme/api#102: Fix login redirect')
        ->assertNoJavaScriptErrors();
});
