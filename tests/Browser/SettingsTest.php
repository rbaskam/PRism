<?php

use App\Enums\NotificationSound;
use App\Models\PullRequest;
use App\Models\Repository;
use App\Services\AppSettings;
use Tests\Browser\Support\FakeGithub;

beforeEach(function () {
    onboardedAccount(['login' => 'octocat', 'name' => 'The Octocat']);
});

it('shows your GitHub account, teams and tracked repositories', function () {
    trackedRepository('acme/api');
    (new FakeGithub)->withTeam('acme', 'reviewers')->fake();
    syncFromGithub();

    visit('/settings/github')
        ->assertSee('The Octocat')
        ->assertSee('@octocat')
        ->assertSee('Your teams')
        ->assertSee('reviewers')
        ->assertSee('Tracked repositories')
        ->assertSee('api')
        ->assertNoJavaScriptErrors();
});

it('tracks a repository picked from your GitHub repositories and syncs its pull requests', function () {
    trackedRepository('acme/api');
    (new FakeGithub)
        ->withRepository('acme/api')
        ->withRepository('acme/web')
        ->withPullRequests('acme/web', [FakeGithub::pullRequest(201, 'Redesign settings')])
        ->fake();

    $page = visit('/settings/github')
        ->click('Add or remove repositories')
        ->waitForText('Tracked')
        ->click('label:has-text("acme/web")')
        ->click('internal:role=button[name="Save (2)"s]');

    $page->navigate('/settings/github')
        ->assertSee('web')
        ->assertNoJavaScriptErrors();

    expect(Repository::query()->tracked()->pluck('full_name')->sort()->values()->all())->toBe(['acme/api', 'acme/web'])
        ->and(PullRequest::query()->pluck('title')->all())->toBe(['Redesign settings']);
});

it('says when Claude Code is not installed', function () {
    visit('/settings/claude')
        ->assertSee("Claude Code wasn't found")
        ->assertNoJavaScriptErrors();
});

it('saves the review prompt and what Claude may do', function () {
    $page = visit('/settings/review')
        ->fill('#review-prompt', 'Review {repository}#{number} for security problems only.')
        ->click('#allow_posting_findings')
        ->click('Save');

    $page->navigate('/settings/review')
        ->assertValue('#review-prompt', 'Review {repository}#{number} for security problems only.')
        ->assertNoJavaScriptErrors();

    $settings = app(AppSettings::class);
    expect($settings->reviewPrompt())->toBe('Review {repository}#{number} for security problems only.')
        ->and($settings->allowsPostingFindings())->toBeTrue();
});

it('saves which desktop alerts you get and their sound', function () {
    $page = visit('/settings/notifications')
        ->click('#mentions')
        ->select('#sound select', 'none')
        ->click('Save');

    $page->navigate('/settings/notifications')
        ->assertSelected('#sound select', 'none')
        ->assertNoJavaScriptErrors();

    $settings = app(AppSettings::class);
    expect($settings->notificationSound())->toBe(NotificationSound::None)
        ->and($settings->get('notifications.mentions'))->toBeFalse();
});

it('turns on the app lock with a PIN', function () {
    $page = visit('/settings/security')
        ->click('#lock_enabled')
        ->fill('#pin', '2468')
        ->fill('#pin_confirmation', '2468')
        ->click('Save');

    $page->navigate('/settings/security')
        ->assertNoJavaScriptErrors();

    expect(app(AppSettings::class)->isAppLockEnabled())->toBeTrue();
});

it('switches to dark mode and an accent colour, and keeps them on every page', function () {
    visit('/settings/appearance')
        ->click('Dark')
        ->click('Violet')
        ->assertScript('document.documentElement.classList.contains("dark")', true)
        ->assertScript('document.documentElement.dataset.accent', 'violet')
        ->navigate('/')
        ->assertScript('document.documentElement.classList.contains("dark")', true)
        ->assertScript('document.documentElement.dataset.accent', 'violet')
        ->assertNoJavaScriptErrors();
});

it('shows the version and the changelog', function () {
    config(['prism.version' => '1.2.3']);

    visit('/settings/about')
        ->assertSee('Version 1.2.3')
        ->assertSee('Updates')
        ->assertSee('Changelog')
        ->assertNoJavaScriptErrors();
});
