<?php

use App\Models\PullRequest;
use App\Models\Repository;
use App\Services\AppSettings;
use Inertia\Testing\AssertableInertia as Assert;

it('lists open pull requests involving the user in tracked repositories, most recently updated first', function () {
    onboardedAccount();
    $awaiting = PullRequest::factory()->awaitingTeamReview('acme/reviewers')->create(['github_updated_at' => now()->subMinutes(5)]);
    $approved = PullRequest::factory()->approvedByMe()->create(['github_updated_at' => now()->subHours(2)]);
    PullRequest::factory()->create();
    PullRequest::factory()->approvedByMe()->closed()->create(['closed_at' => now()->subDays(20)]);
    PullRequest::factory()->awaitingMyReview()->for(Repository::factory()->untracked())->create();

    $response = $this->get(route('dashboard'));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->has('pullRequests', 2)
        ->where('pullRequests.0.id', $awaiting->id)
        ->where('pullRequests.0.attention', 'awaiting')
        ->where('pullRequests.0.requested_via_teams', ['acme/reviewers'])
        ->where('pullRequests.1.id', $approved->id)
        ->where('pullRequests.1.attention', 'approved'));
});

it('keeps pull requests closed in the last two weeks so their AI reviews stay reachable', function () {
    onboardedAccount();
    $closed = PullRequest::factory()->approvedByMe()->closed()->create(['closed_at' => now()->subDays(3)]);

    $response = $this->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->has('pullRequests', 1)
        ->where('pullRequests.0.id', $closed->id)
        ->where('pullRequests.0.state', 'closed'));
});

it('leaves out a pull request a teammate reviewed for one of the user\'s teams', function () {
    onboardedAccount();
    PullRequest::factory()->reviewedByTeammate('acme/reviewers')->create();

    $response = $this->get(route('dashboard'));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page->has('pullRequests', 0));
});

it('lists a pull request a teammate reviewed for one of the user\'s teams when the user keeps them', function () {
    onboardedAccount();
    app(AppSettings::class)->set(AppSettings::KEEP_ANSWERED_TEAM_REQUESTS, true);
    $reviewedForTeam = PullRequest::factory()->reviewedByTeammate('acme/reviewers')->create();
    PullRequest::factory()->create();
    PullRequest::factory()->create(['answered_team_requests' => null]);

    $response = $this->get(route('dashboard'));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('pullRequests', 1)
        ->where('pullRequests.0.id', $reviewedForTeam->id)
        ->where('pullRequests.0.attention', 'none')
        ->where('pullRequests.0.answered_team_requests', ['acme/reviewers']));
});

it('does not say a teammate reviewed a pull request for the user\'s team while the user does not keep them', function () {
    onboardedAccount();
    $assigned = PullRequest::factory()->reviewedByTeammate('acme/reviewers')->create(['is_assigned_to_me' => true]);

    $response = $this->get(route('dashboard'));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('pullRequests', 1)
        ->where('pullRequests.0.id', $assigned->id)
        ->where('pullRequests.0.answered_team_requests', []));
});
