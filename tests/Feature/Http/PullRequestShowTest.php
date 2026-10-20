<?php

use App\Models\PullRequest;
use App\Models\ReviewFinding;
use App\Models\ReviewRun;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

it('shows findings with unresolved and most severe first', function () {
    onboardedAccount();
    $run = ReviewRun::factory()->create();
    $low = ReviewFinding::factory()->for($run)->create(['pull_request_id' => $run->pull_request_id, 'severity' => 'low']);
    $resolvedCritical = ReviewFinding::factory()->for($run)->create(['pull_request_id' => $run->pull_request_id, 'severity' => 'critical', 'resolved_at' => now()]);
    $high = ReviewFinding::factory()->for($run)->create(['pull_request_id' => $run->pull_request_id, 'severity' => 'high']);
    $pullRequest = $run->pullRequest;

    $response = $this->get(route('pull-requests.show', $pullRequest));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('pull-requests/Show')
        ->where('findings.0.id', $high->id)
        ->where('findings.1.id', $low->id)
        ->where('findings.2.id', $resolvedCritical->id)
        ->where('permissions.can_post_findings', false)
        ->missing('conversation'));
});

it('loads the review threads and comments from GitHub when the conversation is requested', function () {
    Http::preventStrayRequests();
    onboardedAccount(['login' => 'octocat']);
    $pullRequest = PullRequest::factory()->create(['number' => 1]);
    Http::fake(['api.github.com/graphql' => Http::response(['data' => ['repository' => ['pullRequest' => githubPullRequestNode([
        'number' => 1,
        'title' => 'Renamed on GitHub',
        'bodyHTML' => '<p>Description</p>',
        'reviewThreads' => ['nodes' => [[
            'id' => 'PRRT_1',
            'isResolved' => false,
            'isOutdated' => false,
            'path' => 'app/Foo.php',
            'line' => 12,
            'originalLine' => 12,
            'comments' => ['nodes' => [[
                'id' => 'PRRC_1', 'databaseId' => 321, 'body' => 'Why?', 'bodyHTML' => '<p>Why?</p>',
                'createdAt' => '2026-10-02T10:00:00Z', 'url' => 'https://github.com/x', 'diffHunk' => '@@ -1 +1 @@',
                'author' => ['login' => 'reviewer', 'avatarUrl' => null],
            ]]],
        ]]],
        'conversation' => ['nodes' => []],
        'reviews' => ['nodes' => []],
    ])]]])]);

    $response = $this->get(route('pull-requests.show', $pullRequest));

    $response->assertInertia(fn (Assert $page) => $page
        ->loadDeferredProps(fn (Assert $reload) => $reload
            ->where('conversation.threads.0.id', 'PRRT_1')
            ->where('conversation.threads.0.diff_hunk', '@@ -1 +1 @@')
            ->where('conversation.threads.0.comments.0.database_id', 321)));
    expect($pullRequest->fresh())
        ->title->toBe('Renamed on GitHub')
        ->body_html->toBe('<p>Description</p>');
});

it('shows the saved conversation without asking GitHub while nothing has changed', function () {
    Http::preventStrayRequests();
    onboardedAccount();
    $pullRequest = PullRequest::factory()->create([
        'github_updated_at' => now()->subHour(),
        'conversation' => ['threads' => [], 'comments' => [['node_id' => 'IC_1', 'body' => 'Saved']], 'reviews' => []],
        'conversation_synced_at' => now()->subMinutes(10),
    ]);

    $response = $this->get(route('pull-requests.show', $pullRequest));

    $response->assertInertia(fn (Assert $page) => $page->where('conversation.comments.0.body', 'Saved'));
    Http::assertNothingSent();
});

it('loads the conversation from GitHub again once the pull request has changed', function () {
    onboardedAccount();
    $pullRequest = PullRequest::factory()->create([
        'github_updated_at' => now(),
        'conversation' => ['threads' => [], 'comments' => [], 'reviews' => []],
        'conversation_synced_at' => now()->subHour(),
    ]);

    $response = $this->get(route('pull-requests.show', $pullRequest));

    $response->assertInertia(fn (Assert $page) => $page->missing('conversation'));
});
