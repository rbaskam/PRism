<?php

use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Models\PendingReviewComment;
use App\Models\PullRequest;
use App\Models\PullRequestFileView;
use App\Models\ReviewFinding;
use App\Models\ReviewRun;
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

/**
 * JavaScript that waits up to five seconds for an element to appear, e.g. syntax colours that load after the diff.
 */
function elementAppears(string $selector): string
{
    $selector = json_encode($selector);

    return <<<JS
        function () {
            return new Promise((resolve) => {
                const started = performance.now();
                const check = () => {
                    if (document.querySelector({$selector})) {
                        resolve(true);
                    } else if (performance.now() - started > 5000) {
                        resolve(false);
                    } else {
                        setTimeout(check, 50);
                    }
                };

                check();
            });
        }
        JS;
}

/**
 * A file of 12 lines where only lines 2 and 11 changed, so lines 4 to 8 are hidden between its two hunks.
 */
function withConfigFileChangedInTwoPlaces(FakeGithub $github): void
{
    $lines = array_map(fn (int $number): string => "line {$number}", range(1, 12));

    $github
        ->withFiles('acme/api', 101, [[
            'filename' => 'config/limits.php',
            'additions' => 2,
            'deletions' => 2,
            'patch' => "@@ -1,3 +1,3 @@\n line 1\n-old 2\n+line 2\n line 3\n@@ -9,4 +9,4 @@\n line 9\n line 10\n-old 11\n+line 11\n line 12",
        ]])
        ->withFileContent('config/limits.php', implode("\n", $lines)."\n");
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

it('shows AI findings under the lines they are about and triages them there', function () {
    $pullRequest = PullRequest::firstWhere('number', 101);
    $finding = ReviewFinding::factory()->create([
        'review_run_id' => ReviewRun::factory()->create(['pull_request_id' => $pullRequest->id])->id,
        'severity' => FindingSeverity::High,
        'title' => 'Limit is applied before authentication',
        'path' => 'app/Http/Middleware/RateLimit.php',
        'line' => 2,
    ]);
    $file = 'section[data-file-path="app/Http/Middleware/RateLimit.php"]';

    $page = visit("/pull-requests/{$pullRequest->id}")
        ->click('internal:role=tab[name="Files changed"i]')
        ->waitForText('// limit requests')
        ->assertSeeIn($file, 'Limit is applied before authentication');

    trackRequests($page);
    $page->click("[data-finding-id=\"{$finding->id}\"] button[aria-label=\"Mark read\"]");
    waitForRequests($page);

    $page->assertNoJavaScriptErrors();
    expect($finding->fresh()->status)->toBe(FindingStatus::Read);
});

it('highlights the words that changed within an edited line', function () {
    $this->github->withFiles('acme/api', 101, [
        ['filename' => 'config/limits.php', 'additions' => 1, 'deletions' => 1, 'patch' => "@@ -1 +1 @@\n-    'per_minute' => 30,\n+    'per_minute' => 60,"],
    ]);
    $pullRequest = PullRequest::firstWhere('number', 101);

    visit("/pull-requests/{$pullRequest->id}")
        ->click('internal:role=tab[name="Files changed"i]')
        ->waitForText('config/limits.php')
        ->assertSeeIn('.diff-row-del mark.diff-word', '30')
        ->assertSeeIn('.diff-row-add mark.diff-word', '60')
        ->assertNoJavaScriptErrors();
});

it('colours the code by its language', function () {
    $pullRequest = PullRequest::firstWhere('number', 101);

    visit("/pull-requests/{$pullRequest->id}")
        ->click('internal:role=tab[name="Files changed"i]')
        ->waitForText('// limit requests')
        ->assertScript(elementAppears('section[data-file-path="app/Http/Middleware/RateLimit.php"] .diff-token'), true)
        ->assertNoJavaScriptErrors();
});

it('shows the diff side by side and remembers the choice', function () {
    withConfigFileChangedInTwoPlaces($this->github);
    $pullRequest = PullRequest::firstWhere('number', 101);

    visit("/pull-requests/{$pullRequest->id}")
        ->click('internal:role=tab[name="Files changed"i]')
        ->waitForText('config/limits.php')
        ->click('button[title="Side by side"]')
        ->assertPresent('[data-path="config/limits.php"][data-side="LEFT"][data-line="2"]')
        ->assertPresent('[data-path="config/limits.php"][data-side="RIGHT"][data-line="2"]')
        ->assertScript('localStorage.getItem("prism.diff-layout")', 'split')
        ->assertNoJavaScriptErrors();
});

it('shows the unchanged lines between the changes', function () {
    withConfigFileChangedInTwoPlaces($this->github);
    $pullRequest = PullRequest::firstWhere('number', 101);

    visit("/pull-requests/{$pullRequest->id}")
        ->click('internal:role=tab[name="Files changed"i]')
        ->waitForText('config/limits.php')
        ->assertDontSee('line 6')
        ->click('internal:role=button[name="Show 5 hidden lines"i]')
        ->waitForText('line 6')
        ->assertSee('line 4')
        ->assertSee('line 8')
        ->assertNoJavaScriptErrors();
});

it('selects lines with the keyboard', function () {
    $pullRequest = PullRequest::firstWhere('number', 101);

    visit("/pull-requests/{$pullRequest->id}")
        ->click('internal:role=tab[name="Files changed"i]')
        ->waitForText('// limit requests')
        ->keys('section[data-file-path="app/Http/Middleware/RateLimit.php"] .diff-table', ['ArrowDown', 'Shift+ArrowDown'])
        ->assertSee('lines 1–2 selected')
        ->assertNoJavaScriptErrors();
});

it('adds comments to your review and posts them when you submit it', function () {
    app(AppSettings::class)->set(AppSettings::ALLOW_REPLYING_TO_COMMENTS, true);
    $pullRequest = PullRequest::firstWhere('number', 101);

    $page = visit("/pull-requests/{$pullRequest->id}")
        ->click('internal:role=tab[name="Files changed"i]')
        ->waitForText('// limit requests')
        ->click('[data-path="app/Http/Middleware/RateLimit.php"][data-line="2"] [data-gutter]')
        ->click('internal:role=button[name="Comment c"s]')
        ->fill('textarea[aria-label="Comment"]', 'Say which requests this limits.')
        ->click('internal:role=button[name="Add to review"i]')
        ->waitForText('Added to your review.')
        ->assertSee('1 comment waiting in your review')
        ->assertSeeIn('[data-pending-comment-id]', 'Say which requests this limits.');

    expect($this->github->sent('POST', '/repos/acme/api/pulls/101/comments'))->toBe([]);

    $page->click('internal:role=button[name="Finish your review"i]')
        ->assertSee('Your comments')
        ->click('[role="dialog"] button[type="submit"]')
        ->waitForText('Approved by you')
        ->assertDontSee('waiting in your review')
        ->assertNoJavaScriptErrors();

    expect($this->github->sent('POST', '/repos/acme/api/pulls/101/reviews')[0]['comments'])->toBe([[
        'side' => 'RIGHT',
        'path' => 'app/Http/Middleware/RateLimit.php',
        'line' => 2,
        'body' => 'Say which requests this limits.',
    ]])
        ->and(PendingReviewComment::count())->toBe(0);
});

it('starts a suggested change from the selected lines', function () {
    app(AppSettings::class)->set(AppSettings::ALLOW_REPLYING_TO_COMMENTS, true);
    $pullRequest = PullRequest::firstWhere('number', 101);

    visit("/pull-requests/{$pullRequest->id}")
        ->click('internal:role=tab[name="Files changed"i]')
        ->waitForText('// limit requests')
        ->click('[data-path="app/Http/Middleware/RateLimit.php"][data-line="2"] [data-gutter]')
        ->click('internal:role=button[name="Comment c"s]')
        ->click('internal:role=button[name="Suggest a change"i]')
        ->assertValue('textarea[aria-label="Comment"]', "```suggestion\n// limit requests\n```\n")
        ->assertNoJavaScriptErrors();
});

it('marks the file at the top viewed with v', function () {
    $pullRequest = PullRequest::firstWhere('number', 101);

    $page = visit("/pull-requests/{$pullRequest->id}")
        ->click('internal:role=tab[name="Files changed"i]')
        ->waitForText('// limit requests');

    trackRequests($page);
    $page->keys('#app', 'v');
    waitForRequests($page);

    $page->assertSee('File viewed')->assertNoJavaScriptErrors();
    expect(PullRequestFileView::where('path', 'app/Http/Middleware/RateLimit.php')->exists())->toBeTrue();
});
