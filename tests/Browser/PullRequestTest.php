<?php

use App\Models\PullRequest;
use App\Services\AppSettings;
use Native\Desktop\Facades\Shell;
use Tests\Browser\Support\FakeGithub;

/**
 * JavaScript that waits for smooth scrolling to settle, then says whether the sticky "Changed files" toolbar is at
 * the top of the window with the given file's card just below it.
 */
function fileIsJustBelowTheToolbar(string $path): string
{
    return <<<JS
        function () {
            return new Promise((resolve) => {
                const started = performance.now();
                const check = () => {
                    const toolbar = document.querySelector('[role="toolbar"][aria-label="Changed files"]').getBoundingClientRect();
                    const file = document.querySelector('[data-file-path="{$path}"]').getBoundingClientRect();
                    const aligned = Math.abs(toolbar.top) < 2 && Math.abs(file.top - toolbar.bottom - 8) <= 4;

                    if (aligned || performance.now() - started > 3000) {
                        resolve(aligned);
                    } else {
                        requestAnimationFrame(check);
                    }
                };

                check();
            });
        }
        JS;
}

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

it('comments on a line in the diff and shows the thread under it', function () {
    app(AppSettings::class)->set(AppSettings::ALLOW_REPLYING_TO_COMMENTS, true);
    $pullRequest = PullRequest::firstWhere('number', 101);
    $file = 'section[data-file-path="app/Http/Middleware/RateLimit.php"]';

    visit("/pull-requests/{$pullRequest->id}")
        ->click('internal:role=tab[name="Files changed"i]')
        ->waitForText('// limit requests')
        ->click('[data-path="app/Http/Middleware/RateLimit.php"][data-line="2"] [data-gutter]')
        ->click('internal:role=button[name="Comment c"s]')
        ->fill('textarea[aria-label="Comment"]', 'Make the limit configurable per client.')
        ->click("{$file} button[type=\"submit\"]")
        ->waitForText('Comment added.')
        ->waitForText('Make the limit configurable per client.')
        ->assertSeeIn($file, 'Make the limit configurable per client.')
        ->assertNoJavaScriptErrors();

    expect($this->github->sent('POST', '/repos/acme/api/pulls/101/comments'))->toBe([[
        'body' => 'Make the limit configurable per client.',
        'commit_id' => 'headsha',
        'path' => 'app/Http/Middleware/RateLimit.php',
        'line' => 2,
        'side' => 'RIGHT',
    ]]);
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

it('keeps the viewed progress in sight and moves on to the next file when you mark one viewed', function () {
    $files = array_map(fn (int $number): array => [
        'filename' => sprintf('src/File%02d.php', $number),
        'additions' => 20,
        'patch' => "@@ -0,0 +1,20 @@\n".implode("\n", array_map(fn (int $line): string => "+// line {$line}", range(1, 20))),
    ], range(1, 30));
    $this->github->withFiles('acme/api', 101, $files);
    $pullRequest = PullRequest::firstWhere('number', 101);

    $page = visit("/pull-requests/{$pullRequest->id}")
        ->click('internal:role=tab[name="Files changed"i]')
        ->waitForText('src/File01.php')
        ->assertSee('0 of 30 files viewed')
        ->click('section[data-file-path="src/File01.php"] [role="checkbox"]')
        ->assertSee('1 of 30 files viewed');

    expect($page->script(fileIsJustBelowTheToolbar('src/File02.php')))->toBeTrue();

    $page->click('section[data-file-path="src/File02.php"] [role="checkbox"]')
        ->assertSee('2 of 30 files viewed')
        ->assertNoJavaScriptErrors();

    expect($page->script(fileIsJustBelowTheToolbar('src/File03.php')))->toBeTrue();
});

it('says when every file has been viewed', function () {
    $pullRequest = PullRequest::firstWhere('number', 101);

    visit("/pull-requests/{$pullRequest->id}")
        ->click('internal:role=tab[name="Files changed"i]')
        ->waitForText('app/Http/Middleware/RateLimit.php')
        ->click('section[data-file-path="app/Http/Middleware/RateLimit.php"] [role="checkbox"]')
        ->waitForText('File viewed')
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

it('snoozes the pull request from its page', function () {
    $pullRequest = PullRequest::firstWhere('number', 101);

    visit("/pull-requests/{$pullRequest->id}")
        ->click('internal:role=button[name="Snooze"s]')
        ->click('internal:role=menuitem[name="For 3 days"i]')
        ->waitForText('Snoozed for 3 days.')
        ->assertSee('Snoozed until')
        ->assertNoJavaScriptErrors();

    expect($pullRequest->fresh()->isSnoozed())->toBeTrue();
});
