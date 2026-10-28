<?php

use App\Models\PullRequest;
use App\Models\Repository;
use Tests\Browser\Support\FakeGithub;

/**
 * Two tracked repositories whose pull requests involve the user ("octocat", in the acme/reviewers team) in every way
 * the dashboard counts, plus one pull request that doesn't involve them at all.
 */
function githubWithPullRequestsForOctocat(): FakeGithub
{
    return (new FakeGithub)
        ->withTeam('acme', 'reviewers')
        ->withPullRequests('acme/api', [
            FakeGithub::pullRequest(101, 'Add rate limiting', FakeGithub::requestedFrom('octocat')),
            FakeGithub::pullRequest(102, 'Fix login redirect', FakeGithub::requestedFromTeam('acme', 'reviewers')),
            FakeGithub::pullRequest(103, 'Upgrade Laravel', FakeGithub::reviewedBy('octocat', 'APPROVED')),
            FakeGithub::pullRequest(104, 'Tidy the README'),
        ])
        ->withPullRequests('acme/web', [
            FakeGithub::pullRequest(201, 'Redesign settings', ['author' => ['login' => 'octocat', 'avatarUrl' => null]]),
            FakeGithub::pullRequest(202, 'New onboarding flow', [
                ...FakeGithub::requestedFrom('octocat'),
                'isDraft' => true,
                'commits' => ['nodes' => [['commit' => ['statusCheckRollup' => ['state' => 'FAILURE']]]]],
            ]),
            FakeGithub::pullRequest(203, 'Bump dependencies', FakeGithub::reviewedBy('octocat', 'CHANGES_REQUESTED')),
        ])
        ->fake();
}

beforeEach(function () {
    onboardedAccount(['login' => 'octocat']);
    trackedRepository('acme/api');
    trackedRepository('acme/web');
});

it('shows the pull requests from GitHub that involve you, with a count for each kind', function () {
    githubWithPullRequestsForOctocat();
    syncFromGithub();

    $page = visit('/');

    $page->assertSee('Add rate limiting')
        ->assertSee('Fix login redirect')
        ->assertSee('Upgrade Laravel')
        ->assertSee('Redesign settings')
        ->assertSee('New onboarding flow')
        ->assertSee('Bump dependencies')
        ->assertDontSee('Tidy the README')
        ->assertSeeIn('button[title="Show only: awaiting your review"]', '3')
        ->assertSeeIn('button[title="Show only: approved by you"]', '1')
        ->assertSeeIn('button[title="Show only: changes requested"]', '1')
        ->assertSeeIn('button[title="Show only: your pull requests"]', '1')
        ->assertSeeIn('button[title="Show only: via teams"]', '1')
        ->assertSeeIn('[data-sidebar="menu-badge"]', '3')
        ->assertNoJavaScriptErrors();
});

it('syncs with GitHub when you come back to the app, e.g. after approving a pull request there', function () {
    $github = githubWithPullRequestsForOctocat();
    syncFromGithub();
    $page = visit('/')->assertSeeIn('[data-sidebar="menu-badge"]', '3');

    $github->withPullRequests('acme/api', [FakeGithub::pullRequest(101, 'Add rate limiting', FakeGithub::reviewedBy('octocat', 'APPROVED'))]);
    $page->script('function () { window.dispatchEvent(new Event("focus")); }');

    $page->waitForText('Syncing…');
    expect($page->script(<<<'JS'
        function () {
            return new Promise((resolve) => {
                const started = performance.now();
                const check = () => {
                    const awaiting = document.querySelector('[data-sidebar="menu-badge"]')?.textContent.trim();

                    if (awaiting === '2' || performance.now() - started > 8000) {
                        resolve(awaiting);
                    } else {
                        setTimeout(check, 100);
                    }
                };

                check();
            });
        }
        JS))->toBe('2');
    $page->assertSeeIn('button[title="Show only: approved by you"]', '2')
        ->assertNoJavaScriptErrors();
});

it('narrows the list to one kind when you click its count, and back again', function () {
    githubWithPullRequestsForOctocat();
    syncFromGithub();

    $page = visit('/')->click('button[title="Show only: awaiting your review"]');

    $page->assertSee('Showing 3 of 6 pull requests')
        ->assertSee('Add rate limiting')
        ->assertSee('Fix login redirect')
        ->assertSee('New onboarding flow')
        ->assertDontSee('Upgrade Laravel')
        ->assertDontSee('Redesign settings');

    $page->click('button[title="Click to remove this filter"]')
        ->assertSee('Showing 6 of 6 pull requests')
        ->assertNoJavaScriptErrors();
});

it('filters by search, repository and how your review was requested', function () {
    githubWithPullRequestsForOctocat();
    syncFromGithub();

    $page = visit('/');

    $page->fill('[aria-label="Search pull requests"]', 'login')
        ->assertSee('Fix login redirect')
        ->assertSee('Showing 1 of 6 pull requests')
        ->click('internal:role=button[name="Clear all"i]');

    $page->select('select[aria-label="Filter by repository"]', (string) Repository::firstWhere('name', 'web')->id)
        ->assertSee('Redesign settings')
        ->assertSee('Bump dependencies')
        ->assertDontSee('Add rate limiting')
        ->click('internal:role=button[name="Clear all"i]');

    $page->select('select[aria-label="Filter by how your review was requested"]', 'team:acme/reviewers')
        ->assertSee('Fix login redirect')
        ->assertSee('Showing 1 of 6 pull requests')
        ->assertNoJavaScriptErrors();
});

it('filters by status and hides drafts or shows only failing checks', function () {
    githubWithPullRequestsForOctocat();
    syncFromGithub();

    $page = visit('/');

    $page->click('[aria-label="Filter by status"] button[title="Approved by you"]')
        ->click('[aria-label="Filter by status"] button[title="You requested changes"]')
        ->assertSee('Upgrade Laravel')
        ->assertSee('Bump dependencies')
        ->assertSee('Showing 2 of 6 pull requests')
        ->click('internal:role=button[name="Clear all"i]');

    $page->click('Hide drafts')
        ->assertDontSee('New onboarding flow')
        ->assertSee('Showing 5 of 6 pull requests')
        ->click('Hide drafts')
        ->click('Failing checks')
        ->assertSee('New onboarding flow')
        ->assertSee('Showing 1 of 6 pull requests')
        ->assertNoJavaScriptErrors();
});

it('remembers your filters when you come back', function () {
    githubWithPullRequestsForOctocat();
    syncFromGithub();

    $page = visit('/')->click('Hide drafts');

    $page->refresh()
        ->assertSee('Showing 5 of 6 pull requests')
        ->assertDontSee('New onboarding flow')
        ->assertNoJavaScriptErrors();
});

it('groups the list by repository', function () {
    githubWithPullRequestsForOctocat();
    syncFromGithub();

    $page = visit('/')->click('Group by repository');

    $page->assertSee('2 awaiting')
        ->assertSee('1 approved')
        ->assertSee('1 awaiting')
        ->assertSee('Collapse all')
        ->click('Collapse all')
        ->assertDontSee('Add rate limiting')
        ->assertSee('Expand all')
        ->assertNoJavaScriptErrors();
});

it('pulls new review requests from GitHub when you click Sync', function () {
    $github = (new FakeGithub)->fake();
    syncFromGithub();
    $page = visit('/')->assertSee('Nothing needs you right now');

    $github->withPullRequests('acme/api', [FakeGithub::pullRequest(101, 'Add rate limiting', FakeGithub::requestedFrom('octocat'))]);
    $page->click('Sync with GitHub');

    $page->waitForText('Add rate limiting')
        ->assertSeeIn('button[title="Show only: awaiting your review"]', '1')
        ->assertNoJavaScriptErrors();
});

it('points out review requests in repositories you do not track', function () {
    (new FakeGithub)->withReviewRequestIn('acme/mobile')->fake();
    syncFromGithub();

    visit('/')
        ->assertSee("You have review requests in 1 repository you aren't tracking")
        ->assertSee('acme/mobile')
        ->assertSee('Track repositories')
        ->assertNoJavaScriptErrors();
});

it('asks you to choose repositories when you track none', function () {
    Repository::query()->update(['is_tracked' => false]);

    visit('/')
        ->assertSee("You aren't tracking any repositories yet")
        ->assertSee('Choose repositories')
        ->assertNoJavaScriptErrors();
});

it('snoozes a pull request out of the list and its counts, and brings it back', function () {
    githubWithPullRequestsForOctocat();
    syncFromGithub();

    $page = visit('/')
        ->hover('div.group:has-text("Add rate limiting")')
        ->click('div.group:has-text("Add rate limiting") button[title="Snooze"]')
        ->click('internal:role=menuitem[name="For 1 week"i]')
        ->waitForText('Snoozed for a week.')
        ->assertDontSee('Add rate limiting')
        ->assertSeeIn('button[title="Show only: awaiting your review"]', '2')
        ->assertSeeIn('[data-sidebar="menu-badge"]', '2')
        ->click('button[aria-pressed]:has-text("Snoozed")')
        ->assertSee('Add rate limiting')
        ->assertSee('Snoozed until');

    expect(PullRequest::firstWhere('number', 101)->isSnoozed())->toBeTrue();

    $page->click('div.group:has-text("Add rate limiting") button[title="Change or end the snooze"]')
        ->click('internal:role=menuitem[name="Unsnooze"i]')
        ->waitForText('No longer snoozed.')
        ->assertSeeIn('[data-sidebar="menu-badge"]', '3')
        ->assertNoJavaScriptErrors();

    expect(PullRequest::firstWhere('number', 101)->isSnoozed())->toBeFalse();
});

it('spells out which filters are hiding pull requests and removes them one at a time', function () {
    githubWithPullRequestsForOctocat();
    syncFromGithub();
    $web = Repository::firstWhere('name', 'web');

    $page = visit('/')
        ->select('select[aria-label="Filter by repository"]', (string) $web->id)
        ->click('[aria-label="Filter by status"] button[title="Awaiting your review"]')
        ->assertSeeIn('[aria-label="Active filters"]', 'Repository: acme/web')
        ->assertSeeIn('[aria-label="Active filters"]', 'Status: Awaiting')
        ->assertScript('getComputedStyle(document.querySelector(\'select[aria-label="Filter by repository"]\')).borderColor !== getComputedStyle(document.querySelector(\'select[aria-label="Sort pull requests"]\')).borderColor')
        ->assertSee('Showing 1 of 6 pull requests');

    $page->click('[aria-label="Active filters"] button[title^="Remove the filter “Repository"]')
        ->assertDontSeeIn('[aria-label="Active filters"]', 'Repository: acme/web')
        ->assertSeeIn('[aria-label="Active filters"]', 'Status: Awaiting')
        ->assertSee('Showing 3 of 6 pull requests')
        ->click('internal:role=button[name="Clear all"i]')
        ->assertMissing('[aria-label="Active filters"]')
        ->assertSee('Showing 6 of 6 pull requests')
        ->assertNoJavaScriptErrors();
});

it('says nothing needs you when your only pull requests were closed recently, and shows them on request', function () {
    (new FakeGithub)->withPullRequests('acme/api', [FakeGithub::pullRequest(101, 'Add rate limiting', FakeGithub::requestedFrom('octocat'))])->fake();
    syncFromGithub();
    PullRequest::firstWhere('number', 101)->update(['state' => PullRequest::STATE_CLOSED, 'closed_at' => now()->subDay()]);

    visit('/')
        ->assertSee('Nothing needs you right now')
        ->assertDontSee('No pull requests match your filters')
        ->assertSee('1 pull request was closed or merged recently.')
        ->click('Show recently closed')
        ->assertSee('Add rate limiting')
        ->assertNoJavaScriptErrors();
});

it('shows which recently closed pull requests were merged, there and on the pull request', function () {
    $github = (new FakeGithub)
        ->withPullRequests('acme/api', [
            FakeGithub::pullRequest(101, 'Add rate limiting', FakeGithub::reviewedBy('octocat', 'APPROVED')),
            FakeGithub::pullRequest(102, 'Fix login redirect', ['author' => ['login' => 'octocat', 'avatarUrl' => null]]),
        ])
        ->fake();
    syncFromGithub();
    $github->merge('acme/api', 101)->close('acme/api', 102);
    syncFromGithub();

    visit('/')
        ->assertSee('2 pull requests were closed or merged recently.')
        ->click('Show recently closed')
        ->assertSeeIn('div.group:has(a[title="Add rate limiting"])', 'Merged')
        ->assertDontSeeIn('div.group:has(a[title="Add rate limiting"])', 'Closed')
        ->assertSeeIn('div.group:has(a[title="Fix login redirect"])', 'Closed')
        ->assertDontSeeIn('div.group:has(a[title="Fix login redirect"])', 'Merged')
        ->click('a[title="Add rate limiting"]')
        ->waitForText('Review history')
        ->assertSeeIn('header:has(h1)', 'Merged')
        ->assertNoJavaScriptErrors();

    expect(PullRequest::firstWhere('number', 101)->merged_at)->not->toBeNull()
        ->and(PullRequest::firstWhere('number', 102)->merged_at)->toBeNull();
});
