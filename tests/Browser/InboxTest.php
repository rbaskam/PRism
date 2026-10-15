<?php

use App\Models\GithubNotification;
use Tests\Browser\Support\FakeGithub;

beforeEach(function () {
    onboardedAccount(['login' => 'octocat']);
    trackedRepository('acme/api');
    trackedRepository('acme/web');

    $this->github = (new FakeGithub)
        ->withPullRequests('acme/api', [
            FakeGithub::pullRequest(101, 'Add rate limiting', FakeGithub::requestedFrom('octocat')),
            FakeGithub::pullRequest(102, 'Fix login redirect'),
        ])
        ->withPullRequests('acme/web', [FakeGithub::pullRequest(201, 'Redesign settings')])
        ->withNotification('1', 'review_requested', 'acme/api', 101, 'Add rate limiting')
        ->withNotification('2', 'mention', 'acme/web', 201, 'Redesign settings')
        ->withNotification('3', 'ci_activity', 'acme/api', 102, 'Fix login redirect')
        ->fake();
    syncFromGithub();
});

it('lists your unread GitHub notifications with a count for each reason', function () {
    visit('/inbox')
        ->assertSee('Add rate limiting')
        ->assertSee('Redesign settings')
        ->assertSee('Fix login redirect')
        ->assertVisible('internal:role=button[name="Unread 3"s]')
        ->assertSee('Review requested')
        ->assertSee('Mentioned')
        ->assertSee('CI')
        ->assertNoJavaScriptErrors();
});

it('filters notifications by reason and by search', function () {
    visit('/inbox')
        ->click('button[aria-pressed]:has-text("Mentioned")')
        ->assertSee('Redesign settings')
        ->assertDontSee('Add rate limiting')
        ->click('All reasons')
        ->fill('[aria-label="Search notifications"]', 'login')
        ->assertSee('Fix login redirect')
        ->assertDontSee('Redesign settings')
        ->assertNoJavaScriptErrors();
});

it('marks a notification as read on GitHub', function () {
    $page = visit('/inbox')
        ->hover('li:has-text("Redesign settings")')
        ->click('li:has-text("Redesign settings") button[title="Mark as read"]');

    // The app serves one request at a time, so this page load waits for the mark-as-read request to finish.
    $page->navigate('/inbox')
        ->assertVisible('internal:role=button[name="Unread 2"s]')
        ->assertDontSee('Redesign settings')
        ->assertNoJavaScriptErrors();

    expect($this->github->sent('PATCH', '/notifications/threads/2'))->toHaveCount(1)
        ->and(GithubNotification::firstWhere('github_id', '2')->is_unread)->toBeFalse();
});

it('marks everything as read on GitHub', function () {
    $page = visit('/inbox')->click('internal:role=button[name="Mark all as read"i]');

    $page->navigate('/inbox')
        ->assertSee('All')
        ->assertDontSee('No notifications yet')
        ->assertNoJavaScriptErrors();

    expect($this->github->sent('PUT', '/notifications'))->toHaveCount(1)
        ->and(GithubNotification::query()->where('is_unread', true)->count())->toBe(0);
});

it('opens a pull request notification in the app', function () {
    visit('/inbox')
        ->click('Add rate limiting')
        ->waitForText('Awaiting your review')
        ->assertPathBeginsWith('/pull-requests/')
        ->assertNoJavaScriptErrors();
});
