<?php

use App\Models\PullRequest;
use App\Models\Repository;
use Tests\Browser\Support\FakeGithub;

/*
 * Every page has to work with each theme setting (see "Themes" in AGENTS.md). Each page is opened in light and dark
 * mode with a non-neutral accent colour, and must load without JavaScript errors.
 */

beforeEach(function () {
    onboardedAccount(['login' => 'octocat']);
    trackedRepository('acme/api');
    (new FakeGithub)
        ->withPullRequests('acme/api', [FakeGithub::pullRequest(101, 'Add rate limiting', FakeGithub::requestedFrom('octocat'))])
        ->withNotification('1', 'review_requested', 'acme/api', 101, 'Add rate limiting')
        ->fake();
    syncFromGithub();
});

dataset('pages', [
    'my pull requests' => fn (): string => '/',
    'repositories' => fn (): string => '/repositories',
    'a repository' => fn (): string => '/repositories/'.Repository::firstOrFail()->id,
    'a pull request' => fn (): string => '/pull-requests/'.PullRequest::firstOrFail()->id,
    'inbox' => fn (): string => '/inbox',
    'GitHub settings' => fn (): string => '/settings/github',
    'Claude settings' => fn (): string => '/settings/claude',
    'PR review settings' => fn (): string => '/settings/review',
    'notification settings' => fn (): string => '/settings/notifications',
    'security settings' => fn (): string => '/settings/security',
    'appearance settings' => fn (): string => '/settings/appearance',
    'about' => fn (): string => '/settings/about',
    'reconnect GitHub' => fn (): string => '/onboarding/github',
]);

it('renders the page in each mode with an accent colour', function (string $path, string $mode) {
    visit('/settings/appearance')
        ->click(ucfirst($mode))
        ->click('Violet')
        ->navigate($path)
        ->assertScript('document.documentElement.classList.contains("dark")', $mode === 'dark')
        ->assertScript('document.documentElement.dataset.accent', 'violet')
        ->assertNoJavaScriptErrors();
})->with('pages')->with(['light', 'dark']);
