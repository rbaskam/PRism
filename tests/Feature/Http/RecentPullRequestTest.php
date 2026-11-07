<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\PullRequest;
use App\Models\Repository;
use Inertia\Testing\AssertableInertia as Assert;

it('remembers when a pull request is opened, but not when the page reloads in the background', function () {
    onboardedAccount();
    $this->travelTo(now()->startOfMinute());
    $pullRequest = PullRequest::factory()->create();

    $this->get(route('pull-requests.show', $pullRequest))->assertOk();

    expect($pullRequest->fresh()->last_viewed_at?->toIso8601String())->toBe(now()->toIso8601String());

    $this->travel(5)->minutes();
    $this->get(route('pull-requests.show', $pullRequest), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        'X-Inertia-Partial-Component' => 'pull-requests/Show',
        'X-Inertia-Partial-Data' => 'counts',
    ])->assertOk();

    expect($pullRequest->fresh()->last_viewed_at?->toIso8601String())->toBe(now()->subMinutes(5)->toIso8601String());
});

it('shares the most recently opened pull requests in tracked repositories, newest first', function () {
    onboardedAccount();
    $repository = trackedRepository('acme/api');
    $pullRequests = collect(range(1, 6))->map(fn (int $minutesAgo): PullRequest => PullRequest::factory()->for($repository)->create([
        'number' => $minutesAgo,
        'title' => "Viewed {$minutesAgo} minutes ago",
        'last_viewed_at' => now()->subMinutes($minutesAgo),
    ]));
    PullRequest::factory()->for($repository)->create(['last_viewed_at' => null]);
    PullRequest::factory()->for(Repository::factory()->untracked())->create(['last_viewed_at' => now()]);
    $pullRequests[1]->update(['state' => PullRequest::STATE_CLOSED, 'closed_at' => now(), 'merged_at' => now()]);

    $response = $this->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->has('recentPullRequests', PullRequest::RECENTLY_VIEWED_LIMIT)
        ->where('recentPullRequests.0', [
            'id' => $pullRequests[0]->id,
            'reference' => 'acme/api#1',
            'title' => 'Viewed 1 minutes ago',
            'state' => 'open',
            'is_draft' => false,
            'merged_at' => null,
            'has_unseen_findings' => false,
        ])
        ->where('recentPullRequests.1.state', 'closed')
        ->where('recentPullRequests.1.merged_at', fn (?string $mergedAt) => $mergedAt !== null)
        ->where('recentPullRequests.4.reference', 'acme/api#5'));
});

it('clears the recently viewed list without touching the pull requests', function () {
    onboardedAccount();
    $pullRequest = PullRequest::factory()->create(['last_viewed_at' => now()]);

    $response = $this->deleteJson(route('recent-pull-requests.destroy'));

    $response->assertNoContent();
    expect($pullRequest->fresh())->not->toBeNull()
        ->last_viewed_at->toBeNull();
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('recentPullRequests', []));
});

it('points out new AI findings in lists until the pull request is opened', function () {
    onboardedAccount();
    $pullRequest = PullRequest::factory()->for(trackedRepository('acme/api'))->awaitingMyReview()->create([
        'last_viewed_at' => now()->subHour(),
        'unseen_findings_at' => now(),
    ]);

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('pullRequests.0.has_unseen_findings', true)
        ->where('recentPullRequests.0.has_unseen_findings', true));

    $this->get(route('pull-requests.show', $pullRequest))->assertOk();

    expect($pullRequest->fresh()->hasUnseenFindings())->toBeFalse();
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('pullRequests.0.has_unseen_findings', false)
        ->where('recentPullRequests.0.has_unseen_findings', false));
});

it('counts new AI findings as seen when they load on the open pull request, but not on other background reloads', function () {
    onboardedAccount();
    $pullRequest = PullRequest::factory()->create(['unseen_findings_at' => now()]);
    $reload = fn (string $props) => $this->get(route('pull-requests.show', $pullRequest), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        'X-Inertia-Partial-Component' => 'pull-requests/Show',
        'X-Inertia-Partial-Data' => $props,
    ])->assertOk();

    $reload('counts,recentPullRequests');

    expect($pullRequest->fresh())
        ->hasUnseenFindings()->toBeTrue()
        ->last_viewed_at->toBeNull();

    $reload('reviewRuns,findings,pullRequest');

    expect($pullRequest->fresh())
        ->hasUnseenFindings()->toBeFalse()
        ->last_viewed_at->toBeNull();
});
