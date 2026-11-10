<?php

use App\Models\PullRequest;
use App\Services\AppLock;
use App\Services\AppSettings;
use Pest\Browser\Api\AwaitableWebpage;
use Tests\Browser\Support\FakeGithub;

/*
 * Pages are served with a Content Security Policy (see AddContentSecurityPolicy). The browser blocks anything the
 * policy doesn't allow without throwing an error, so these tests ask it for the violations it reported.
 */

/**
 * The Content Security Policy violations the browser has reported on the page so far, as "directive blocked-url".
 *
 * @return list<string>
 */
function contentSecurityPolicyViolations(AwaitableWebpage $page): array
{
    return $page->script(<<<'JS'
        function () {
            const observer = new ReportingObserver(() => {}, { types: ['csp-violation'], buffered: true });
            observer.observe();

            return observer.takeRecords().map((report) => `${report.body.effectiveDirective} ${report.body.blockedURL}`);
        }
        JS);
}

beforeEach(function () {
    onboardedAccount(['login' => 'octocat']);
    trackedRepository('acme/api');
    (new FakeGithub)
        ->withPullRequests('acme/api', [
            FakeGithub::pullRequest(101, 'Add rate limiting', [
                ...FakeGithub::requestedFrom('octocat'),
                'bodyHTML' => '<p>Limits each client to 60 requests a minute.</p><p><img src="https://example.com/diagram.png" alt="Diagram"></p>',
                'conversation' => ['nodes' => [FakeGithub::comment('hubot', 'Should the limit be configurable?')]],
            ]),
        ])
        ->withFiles('acme/api', 101, [
            ['filename' => 'app/Http/Middleware/RateLimit.php', 'status' => 'added', 'additions' => 2, 'patch' => "@@ -0,0 +1,2 @@\n+<?php\n+// limit requests"],
        ])
        ->fake();
    syncFromGithub();
});

it('runs the lock screen within the policy', function () {
    app(AppLock::class)->setPin('2468');
    app(AppSettings::class)->set(AppSettings::APP_LOCK_ENABLED, true);

    $page = visit('/')
        ->assertPathIs('/lock')
        ->fill('#pin', '2468')
        ->click('internal:role=button[name="Unlock"s]')
        ->waitForText('My pull requests')
        ->assertNoJavaScriptErrors();

    expect(contentSecurityPolicyViolations($page))->toBe([]);
});

it('runs a pull request, with its description, conversation and highlighted diff, within the policy', function () {
    $pullRequest = PullRequest::firstWhere('number', 101);

    $page = visit("/pull-requests/{$pullRequest->id}")
        ->click('internal:role=tab[name="Description"i]')
        ->assertSee('Limits each client to 60 requests a minute.')
        ->click('internal:role=tab[name="Conversation"i]')
        ->waitForText('Should the limit be configurable?')
        ->click('internal:role=tab[name="Files changed"i]')
        ->waitForText('app/Http/Middleware/RateLimit.php')
        ->assertScript(<<<'JS'
            function () {
                return new Promise((resolve) => {
                    const started = performance.now();
                    const check = () => {
                        if (document.querySelector('.diff-token') || performance.now() - started > 5000) {
                            resolve(true);
                        } else {
                            setTimeout(check, 50);
                        }
                    };

                    check();
                });
            }
            JS, true)
        ->assertNoJavaScriptErrors();

    expect(contentSecurityPolicyViolations($page))->toBe([]);
});

it('runs the other pages within the policy', function (string $path) {
    $page = visit($path)->assertNoJavaScriptErrors();

    expect(contentSecurityPolicyViolations($page))->toBe([]);
})->with([
    'repositories' => '/repositories',
    'inbox' => '/inbox',
    'security settings' => '/settings/security',
    'appearance settings' => '/settings/appearance',
    'reconnect GitHub' => '/onboarding/github',
]);
