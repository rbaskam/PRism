<?php

use App\Enums\NotificationSound;
use App\Models\GithubAccount;
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

    $page = visit('/settings/github');
    trackRequests($page);
    $page->click('Add or remove repositories')
        ->waitForText('Tracked')
        ->click('label:has-text("acme/web")')
        ->click('internal:role=button[name="Save (2)"s]');
    waitForRequests($page);

    $page->navigate('/settings/github')
        ->assertSee('web')
        ->assertNoJavaScriptErrors();

    expect(Repository::query()->tracked()->pluck('full_name')->sort()->values()->all())->toBe(['acme/api', 'acme/web'])
        ->and(PullRequest::query()->pluck('title')->all())->toBe(['Redesign settings']);
});

it('reconnects GitHub and comes back to the settings without going through setup again', function () {
    GithubAccount::current()->update(['github_id' => FakeGithub::USER_ID]);
    trackedRepository('acme/api');
    (new FakeGithub)->fake();

    visit('/settings/github')
        ->click('Reconnect')
        ->waitForText('Reconnect GitHub')
        ->assertPathIs('/onboarding/github')
        ->assertMissing('[aria-label="Setup steps"]')
        ->assertSee('Connected as @octocat')
        ->click('Back to Settings')
        ->waitForText('Tracked repositories')
        ->assertPathIs('/settings/github')
        ->click('Reconnect')
        ->waitForText('Reconnect GitHub')
        ->fill('#github-token', 'ghp_test')
        ->click('internal:role=button[name="Connect"s]')
        ->waitForText('Reconnected to GitHub as @octocat.')
        ->assertPathIs('/settings/github')
        ->assertSee('Tracked repositories')
        ->assertNoJavaScriptErrors();

    expect(GithubAccount::sole()->token)->toBe('ghp_test');
});

it('says when Claude Code is not installed', function () {
    visit('/settings/claude')
        ->assertSee("Claude Code wasn't found")
        ->assertNoJavaScriptErrors();
});

it('saves the review prompt and what Claude may do', function () {
    $page = visit('/settings/review');
    trackRequests($page);
    $page->fill('#review-prompt', 'Review {repository}#{number} for security problems only.')
        ->click('#allow_posting_findings')
        ->click('Save');
    waitForRequests($page);

    $page->navigate('/settings/review')
        ->assertValue('#review-prompt', 'Review {repository}#{number} for security problems only.')
        ->assertNoJavaScriptErrors();

    $settings = app(AppSettings::class);
    expect($settings->reviewPrompt())->toBe('Review {repository}#{number} for security problems only.')
        ->and($settings->allowsPostingFindings())->toBeTrue();
});

it('saves which desktop alerts you get and their sound', function () {
    $page = visit('/settings/notifications');
    trackRequests($page);
    $page->click('#mentions')
        ->click('label[for="sound"]')
        ->assertScript('document.activeElement?.id', 'sound')
        ->select('#sound', 'none')
        ->click('Save');
    waitForRequests($page);

    $page->navigate('/settings/notifications')
        ->assertSelected('#sound', 'none')
        ->assertNoJavaScriptErrors();

    $settings = app(AppSettings::class);
    expect($settings->notificationSound())->toBe(NotificationSound::None)
        ->and($settings->get('notifications.mentions'))->toBeFalse();
});

it('turns on the app lock with a PIN', function () {
    $page = visit('/settings/security');
    trackRequests($page);
    $page->click('#lock_enabled')
        ->fill('#pin', '2468')
        ->fill('#pin_confirmation', '2468')
        ->click('Save');
    waitForRequests($page);

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

it('marks Settings as the current page in the sidebar on every settings page', function () {
    visit('/settings/notifications')
        ->assertScript('[...document.querySelectorAll(\'a[aria-current="page"]\')].map((link) => link.textContent.trim()).join()', 'Notifications')
        ->assertScript('document.querySelector(\'[data-sidebar="menu-button"][data-active="true"]\')?.textContent.trim()', 'Settings')
        ->assertNoJavaScriptErrors();
});
