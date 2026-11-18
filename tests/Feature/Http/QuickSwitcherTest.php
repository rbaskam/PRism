<?php

use App\Models\PendingReviewComment;
use App\Models\PullRequest;

beforeEach(function () {
    onboardedAccount(['login' => 'octocat']);
});

it('lists tracked repositories with their counts, and their open pull requests before recently closed ones', function () {
    $api = trackedRepository('acme/api');
    $web = trackedRepository('acme/web');
    $closed = PullRequest::factory()->for($api)->closed()->create([
        'number' => 7,
        'title' => 'Fix login redirect',
        'github_updated_at' => now()->subMinute(),
    ]);
    $awaiting = PullRequest::factory()->for($web)->awaitingMyReview()->create([
        'number' => 42,
        'title' => 'Add rate limiting',
        'author_login' => 'hubot',
        'head_ref' => 'feature/rate-limits',
        'github_updated_at' => now()->subDay(),
    ]);
    $mine = PullRequest::factory()->for($api)->authoredByMe()->create(['github_updated_at' => now()->subHour()]);

    $response = $this->getJson(route('quick-switcher'));

    $response->assertOk()
        ->assertJsonPath('repositories', [
            ['id' => $api->id, 'full_name' => 'acme/api', 'counts' => ['open' => 1, 'awaiting' => 0, 'approved' => 0]],
            ['id' => $web->id, 'full_name' => 'acme/web', 'counts' => ['open' => 1, 'awaiting' => 1, 'approved' => 0]],
        ])
        ->assertJsonPath('pull_requests.*.id', [$mine->id, $awaiting->id, $closed->id])
        ->assertJsonPath('pull_requests.1', [
            'id' => $awaiting->id,
            'repository' => 'acme/web',
            'number' => 42,
            'title' => 'Add rate limiting',
            'state' => 'open',
            'merged_at' => null,
            'is_draft' => false,
            'author_login' => 'hubot',
            'head_ref' => 'feature/rate-limits',
            'attention' => 'awaiting',
        ])
        ->assertJsonPath('pull_requests.0.attention', 'authored')
        ->assertJsonPath('pull_requests.2.state', 'closed')
        ->assertJsonPath('pull_requests.2.merged_at', $closed->merged_at?->toIso8601String());
});

it('counts an approved pull request with review comments drafted in PRism as awaiting, not approved', function () {
    $api = trackedRepository('acme/api');
    $drafted = PullRequest::factory()->for($api)->approvedByMe()->create(['github_updated_at' => now()->subMinute()]);
    PendingReviewComment::factory()->for($drafted)->create();
    $assigned = PullRequest::factory()->for($api)->assignedToMe()->create(['github_updated_at' => now()->subHour()]);

    $response = $this->getJson(route('quick-switcher'));

    $response->assertOk()
        ->assertJsonPath('repositories.0.counts', ['open' => 2, 'awaiting' => 1, 'approved' => 0])
        ->assertJsonPath('pull_requests.*.id', [$drafted->id, $assigned->id])
        ->assertJsonPath('pull_requests.*.attention', ['awaiting', 'assigned']);
});

it('leaves out untracked repositories and pull requests closed too long ago', function () {
    $api = trackedRepository('acme/api');
    $untracked = trackedRepository('acme/legacy', ['is_tracked' => false]);
    $open = PullRequest::factory()->for($api)->create();
    PullRequest::factory()->for($api)->closed()->create(['closed_at' => now()->subDays(PullRequest::RECENTLY_CLOSED_DAYS + 1)]);
    PullRequest::factory()->for($untracked)->create();

    $response = $this->getJson(route('quick-switcher'));

    $response->assertOk()
        ->assertJsonPath('repositories.*.full_name', ['acme/api'])
        ->assertJsonPath('pull_requests.*.id', [$open->id]);
});
