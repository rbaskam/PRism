<?php

use App\Models\GithubAccount;
use App\Models\Repository;
use Tests\Browser\Support\FakeGithub;

it('connects GitHub, tracks a repository and lands on its pull requests', function () {
    (new FakeGithub)
        ->withRepository('acme/api')
        ->withRepository('acme/web')
        ->withPullRequests('acme/api', [FakeGithub::pullRequest(101, 'Add rate limiting', FakeGithub::requestedFrom('octocat'))])
        ->fake();

    visit('/')
        ->assertPathIs('/onboarding/github')
        ->assertSee('Connect GitHub')
        ->fill('#github-token', 'ghp_'.str_repeat('x', 36))
        ->click('internal:role=button[name="Connect"s]')
        ->waitForText('Choose repositories')
        ->assertPathIs('/onboarding/repositories')
        ->waitForText('web')
        ->click('label:has-text("acme/api")')
        ->click('Track 1 repository')
        ->waitForText('Connect Claude')
        ->assertPathIs('/onboarding/claude')
        ->click('Skip for now — connect Claude later in Settings')
        ->waitForText('Add rate limiting')
        ->assertPathIs('/')
        ->assertSeeIn('button[title="Show only: awaiting your review"]', '1')
        ->assertNoJavaScriptErrors();

    expect(GithubAccount::current()->login)->toBe('octocat')
        ->and(Repository::query()->tracked()->pluck('full_name')->all())->toBe(['acme/api']);
});

it('keeps you on the first step until GitHub is connected', function () {
    visit('/repositories')
        ->assertPathIs('/onboarding/github')
        ->assertSee('Paste a personal access token')
        ->assertNoJavaScriptErrors();
});
