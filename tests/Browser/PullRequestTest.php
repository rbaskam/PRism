<?php

use App\Models\PullRequest;
use Native\Desktop\Facades\Shell;
use Tests\Browser\Support\FakeGithub;

beforeEach(function () {
    onboardedAccount(['login' => 'octocat']);
    trackedRepository('acme/api');

    $this->github = (new FakeGithub)
        ->withPullRequests('acme/api', [
            FakeGithub::pullRequest(101, 'Add rate limiting', [
                ...FakeGithub::requestedFrom('octocat'),
                'bodyHTML' => '<p>Limits each client to 60 requests a minute.</p>',
                'conversation' => ['nodes' => [FakeGithub::comment('hubot', 'Should the limit be configurable?')]],
                'labels' => ['nodes' => [['name' => 'backend', 'color' => '0e8a16']]],
            ]),
        ])
        ->withFiles('acme/api', 101, [
            ['filename' => 'app/Http/Middleware/RateLimit.php', 'status' => 'added', 'additions' => 2, 'patch' => "@@ -0,0 +1,2 @@\n+<?php\n+// limit requests"],
        ])
        ->fake();
    syncFromGithub();
});

it('shows the pull request with its conversation loaded from GitHub', function () {
    $pullRequest = PullRequest::firstWhere('number', 101);

    visit("/pull-requests/{$pullRequest->id}")
        ->assertSee('Add rate limiting')
        ->assertSee('Awaiting your review')
        ->assertSee('backend')
        ->assertSee('Review requested from')
        ->click('internal:role=tab[name="Conversation"i]')
        ->waitForText('Should the limit be configurable?')
        ->assertSee('hubot')
        ->click('internal:role=tab[name="Description"i]')
        ->assertSee('Limits each client to 60 requests a minute.')
        ->assertNoJavaScriptErrors();
});

it('loads the changed files from GitHub when you open the tab', function () {
    $pullRequest = PullRequest::firstWhere('number', 101);

    visit("/pull-requests/{$pullRequest->id}")
        ->click('internal:role=tab[name="Files changed"i]')
        ->waitForText('app/Http/Middleware/RateLimit.php')
        ->assertSee('// limit requests')
        ->assertNoJavaScriptErrors();
});

it('approves the pull request on GitHub and takes it out of your queue', function () {
    $pullRequest = PullRequest::firstWhere('number', 101);

    $page = visit("/pull-requests/{$pullRequest->id}")
        ->click('internal:role=button[name="Approve"s]')
        ->assertSee('Submit your review')
        ->click('[role="dialog"] button[type="submit"]')
        ->waitForText('Approved by you')
        ->assertNoJavaScriptErrors();

    expect($this->github->sent('POST', '/repos/acme/api/pulls/101/reviews'))->toHaveCount(1)
        ->and($this->github->sent('POST', '/repos/acme/api/pulls/101/reviews')[0]['event'])->toBe('APPROVE');

    $page->navigate('/')
        ->assertSeeIn('button[title="Show only: awaiting your review"]', '0')
        ->assertSeeIn('button[title="Show only: approved by you"]', '1')
        ->assertNoJavaScriptErrors();
});

it('keeps showing the changed files after syncing with GitHub', function () {
    $pullRequest = PullRequest::firstWhere('number', 101);

    visit("/pull-requests/{$pullRequest->id}")
        ->click('internal:role=tab[name="Files changed"i]')
        ->waitForText('app/Http/Middleware/RateLimit.php')
        ->click('Sync with GitHub')
        ->waitForText('Syncing with GitHub…')
        ->waitForText('app/Http/Middleware/RateLimit.php')
        ->assertDontSee('Loading the changed files from GitHub…')
        ->assertNoJavaScriptErrors();
});

it('opens a file on GitHub from the desktop app without reloading the changed files', function () {
    config(['nativephp-internal.running' => true]);
    Shell::fake();
    $pullRequest = PullRequest::firstWhere('number', 101);

    $page = visit("/pull-requests/{$pullRequest->id}")
        ->click('internal:role=tab[name="Files changed"i]')
        ->waitForText('app/Http/Middleware/RateLimit.php')
        ->click('section[data-file-path="app/Http/Middleware/RateLimit.php"] header a[href$="/files"]');

    // The app serves one request at a time, so this request is answered after the one that opened the link.
    $page->script('function () { return fetch("/settings/about").then((response) => response.status); }');

    $page->assertSee('app/Http/Middleware/RateLimit.php')
        ->assertDontSee('Loading the changed files from GitHub…')
        ->assertNoJavaScriptErrors();
    Shell::assertOpenedExternal('https://github.com/acme/api/pull/101/files');
});
