<?php

use App\Models\Repository;
use Tests\Browser\Support\FakeGithub;

beforeEach(function () {
    onboardedAccount(['login' => 'octocat']);
    trackedRepository('acme/api');
    trackedRepository('acme/web', ['is_private' => false]);

    (new FakeGithub)
        ->withPullRequests('acme/api', [
            FakeGithub::pullRequest(101, 'Add rate limiting', FakeGithub::requestedFrom('octocat')),
            FakeGithub::pullRequest(102, 'Upgrade Laravel', FakeGithub::reviewedBy('octocat', 'APPROVED')),
            FakeGithub::pullRequest(103, 'Tidy the README'),
        ])
        ->withPullRequests('acme/web', [
            FakeGithub::pullRequest(201, 'Redesign settings'),
        ])
        ->fake();
    syncFromGithub();
});

it('lists tracked repositories with their open pull requests and what waits on you', function () {
    $api = Repository::firstWhere('name', 'api');
    $web = Repository::firstWhere('name', 'web');

    visit('/repositories')
        ->assertSee('Repositories')
        ->assertSeeIn("a[href$='/repositories/{$api->id}']", '3')
        ->assertSeeIn("a[href$='/repositories/{$api->id}']", 'open pull requests')
        ->assertSeeIn("a[href$='/repositories/{$api->id}']", '1 awaiting your review')
        ->assertSeeIn("a[href$='/repositories/{$api->id}']", '1 approved by you')
        ->assertSeeIn("a[href$='/repositories/{$api->id}']", 'Private')
        ->assertSeeIn("a[href$='/repositories/{$web->id}']", 'open pull request')
        ->assertSeeIn("a[href$='/repositories/{$web->id}']", 'Nothing waiting on you')
        ->assertSeeIn("a[href$='/repositories/{$web->id}']", 'Public')
        ->assertNoJavaScriptErrors();
});

it('shows long repository names in full', function () {
    trackedRepository('acme-technology-holdings/payments-reconciliation-service');

    visit('/repositories')
        ->assertSee('payments-reconciliation-service')
        ->assertSee('acme-technology-holdings')
        ->assertScript(<<<'JS'
            () => {
                const name = [...document.querySelectorAll('p')].find((element) => element.textContent.trim() === 'payments-reconciliation-service');

                return name !== undefined && name.scrollWidth <= name.clientWidth;
            }
            JS)
        ->assertNoJavaScriptErrors();
});

it('filters the repositories as you type', function () {
    visit('/repositories')
        ->fill('[placeholder="Filter repositories…"]', 'web')
        ->assertSee('Nothing waiting on you')
        ->assertDontSee('1 approved by you')
        ->fill('[placeholder="Filter repositories…"]', 'mobile')
        ->assertSee('No repositories match “mobile”')
        ->assertNoJavaScriptErrors();
});

it('shows every open pull request in a repository, including ones that do not involve you', function () {
    $repository = Repository::firstWhere('name', 'api');

    visit('/repositories')
        ->click('api')
        ->assertPathIs("/repositories/{$repository->id}")
        ->assertSee('acme/api')
        ->assertSee('Add rate limiting')
        ->assertSee('Upgrade Laravel')
        ->assertSee('Tidy the README')
        ->assertDontSee('Redesign settings')
        ->assertSeeIn('button[title="Show only: awaiting your review"]', '1')
        ->click('button[title="Show only: approved by you"]')
        ->assertSee('Showing 1 of 3 pull requests')
        ->assertSee('Upgrade Laravel')
        ->assertNoJavaScriptErrors();
});

it('says when a repository has no open pull requests', function () {
    $repository = trackedRepository('acme/docs');

    visit("/repositories/{$repository->id}")
        ->assertSee('No open pull requests in acme/docs')
        ->assertNoJavaScriptErrors();
});

it('gives a repository its own review prompt, and goes back to the app-wide one', function () {
    $repository = Repository::firstWhere('name', 'api');

    $page = visit("/repositories/{$repository->id}")
        ->click('internal:role=button[name="Review prompt"s]')
        ->assertSee('Review prompt for acme/api')
        ->assertSee('It uses the app-wide prompt.')
        ->fill('#repository-review-prompt', 'Only check ')
        ->click('internal:role=button[name="{repository}"i]')
        ->click('internal:role=button[name="Save"s]')
        ->waitForText('Saved the review prompt for acme/api.')
        ->assertNoJavaScriptErrors();

    expect($repository->fresh()->review_prompt)->toBe('Only check {repository}');

    $page->navigate('/settings/review')
        ->assertSee('Using their own prompt:')
        ->assertSeeIn("a[href$='/repositories/{$repository->id}']", 'acme/api')
        ->navigate("/repositories/{$repository->id}")
        ->click('internal:role=button[name="Review prompt Custom"s]')
        ->assertValue('#repository-review-prompt', 'Only check {repository}')
        ->click('internal:role=button[name="Use the app-wide prompt"s]')
        ->waitForText('acme/api now uses the app-wide review prompt.')
        ->assertNoJavaScriptErrors();

    expect($repository->fresh()->review_prompt)->toBeNull();
});
