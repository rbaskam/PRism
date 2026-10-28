<?php

use App\Jobs\PollNotifications;
use App\Jobs\SyncGithub;
use App\Models\GithubAccount;
use App\Models\Repository;
use App\Services\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;
use Tests\BrowserTestCase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(BrowserTestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Browser');

/*
 * Pages load their GitHub data through deferred props and polls, so give them longer than the default five seconds.
 */
pest()->browser()->timeout(10000);

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Connect a GitHub account and finish onboarding, as a returning user of the app would have.
 *
 * @param  array<string, mixed>  $attributes
 */
function onboardedAccount(array $attributes = []): GithubAccount
{
    app(AppSettings::class)->set(AppSettings::ONBOARDING_COMPLETED_AT, now()->toIso8601String());

    return GithubAccount::factory()->create($attributes);
}

/**
 * Build a pull request node shaped like GitHub's GraphQL API response.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function githubPullRequestNode(array $overrides = []): array
{
    $number = $overrides['number'] ?? 1;

    return array_replace([
        'id' => "PR_node_{$number}",
        'number' => $number,
        'title' => "Pull request {$number}",
        'url' => "https://github.com/acme/app/pull/{$number}",
        'state' => 'OPEN',
        'isDraft' => false,
        'createdAt' => '2026-10-01T10:00:00Z',
        'updatedAt' => '2026-10-02T10:00:00Z',
        'closedAt' => null,
        'mergedAt' => null,
        'additions' => 10,
        'deletions' => 2,
        'changedFiles' => 1,
        'headRefName' => 'feature/thing',
        'baseRefName' => 'main',
        'headRefOid' => 'headsha',
        'mergeable' => 'MERGEABLE',
        'reviewDecision' => 'REVIEW_REQUIRED',
        'author' => ['login' => 'someone-else', 'avatarUrl' => 'https://avatars.githubusercontent.com/u/2'],
        'assignees' => ['nodes' => []],
        'labels' => ['nodes' => []],
        'comments' => ['totalCount' => 0],
        'reviewRequests' => ['nodes' => []],
        'reviewRequestedEvents' => ['nodes' => []],
        'latestOpinionatedReviews' => ['nodes' => []],
        'latestReviews' => ['nodes' => []],
        'commits' => ['nodes' => [['commit' => ['statusCheckRollup' => ['state' => 'SUCCESS']]]]],
    ], $overrides);
}

/**
 * Wrap pull request nodes in the GraphQL response for a repository's open pull requests.
 *
 * @param  list<array<string, mixed>>  $nodes
 * @return array<string, mixed>
 */
function githubOpenPullRequestsResponse(array $nodes): array
{
    return ['data' => ['repository' => ['pullRequests' => [
        'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
        'nodes' => $nodes,
    ]]]];
}

/**
 * Sync the connected account, tracked repositories and notifications from GitHub (a FakeGithub in browser tests),
 * as the scheduler and the Sync button do in the app.
 */
function syncFromGithub(): void
{
    SyncGithub::dispatchSync();
    PollNotifications::dispatchSync();
}

/**
 * Track a repository by its "owner/name", as if the user picked it in Settings → GitHub.
 *
 * @param  array<string, mixed>  $attributes
 */
function trackedRepository(string $fullName, array $attributes = []): Repository
{
    [$owner, $name] = explode('/', $fullName);

    return Repository::factory()->create(['owner' => $owner, 'name' => $name, 'full_name' => $fullName, ...$attributes]);
}

/**
 * Count the page's fetch and XHR requests that haven't been answered yet, for waitForRequests(). Call it once the
 * page has loaded and before the action that sends the requests.
 */
function trackRequests(PendingAwaitablePage|AwaitableWebpage $page): void
{
    $page->script(<<<'JS'
        function () {
            window.__pendingRequests = 0;
            const originalFetch = window.fetch;
            window.fetch = (...args) => {
                window.__pendingRequests++;
                return originalFetch(...args).finally(() => window.__pendingRequests--);
            };
            const originalSend = XMLHttpRequest.prototype.send;
            XMLHttpRequest.prototype.send = function (...args) {
                window.__pendingRequests++;
                this.addEventListener('loadend', () => window.__pendingRequests--, { once: true });
                return originalSend.apply(this, args);
            };
        }
        JS);
}

/**
 * Wait until every request counted by trackRequests() has been answered, e.g. after clicking a button that saves
 * something and before navigating away or checking the database. Navigating straight away can cancel a request
 * the browser hasn't sent yet.
 */
function waitForRequests(PendingAwaitablePage|AwaitableWebpage $page): void
{
    $page->script('function () { return new Promise((resolve) => { const check = () => (window.__pendingRequests === 0 ? resolve(true) : setTimeout(check, 25)); check(); }); }');
}
