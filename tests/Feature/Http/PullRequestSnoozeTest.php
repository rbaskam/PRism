<?php

use App\Models\PullRequest;
use App\Models\Repository;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    onboardedAccount();
});

it('snoozes a pull request for the chosen number of days', function () {
    $this->travelTo('2026-10-15 10:00:00');
    $pullRequest = PullRequest::factory()->awaitingMyReview()->create();

    $response = $this->post(route('pull-requests.snooze.store', $pullRequest), ['days' => 3]);

    $response->assertRedirect()->assertSessionHas('success', 'Snoozed for 3 days.');
    expect($pullRequest->fresh()->snoozed_until->toDateTimeString())->toBe('2026-10-18 10:00:00');
});

it('only snoozes for a day, three days or a week', function () {
    $pullRequest = PullRequest::factory()->create();

    $response = $this->post(route('pull-requests.snooze.store', $pullRequest), ['days' => 30]);

    $response->assertSessionHasErrors('days');
    expect($pullRequest->fresh()->snoozed_until)->toBeNull();
});

it('ends a snooze', function () {
    $pullRequest = PullRequest::factory()->snoozed()->create();

    $response = $this->delete(route('pull-requests.snooze.destroy', $pullRequest));

    $response->assertSessionHas('success', 'No longer snoozed.');
    expect($pullRequest->fresh()->snoozed_until)->toBeNull();
});

it('leaves snoozed pull requests out of the awaiting review counts until the snooze ends', function () {
    $repository = Repository::factory()->create();
    PullRequest::factory()->for($repository)->awaitingMyReview()->create();
    PullRequest::factory()->for($repository)->awaitingMyReview()->snoozed(now()->addDay())->create();

    $this->get(route('repositories.index'))->assertInertia(fn (Assert $page) => $page
        ->where('counts.awaiting_review', 1)
        ->where('repositories.0.counts.awaiting', 1));

    $this->travel(2)->days();

    $this->get(route('repositories.index'))->assertInertia(fn (Assert $page) => $page
        ->where('counts.awaiting_review', 2)
        ->where('repositories.0.counts.awaiting', 2));
});

it('tells the page until when a pull request is snoozed, and not after the snooze ends', function () {
    $this->travelTo('2026-10-15 10:00:00');
    $snoozed = PullRequest::factory()->awaitingMyReview()->snoozed(now()->addDay())->create();
    $expired = PullRequest::factory()->awaitingMyReview()->snoozed(now()->subMinute())->create();

    $response = $this->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('pullRequests', fn ($pullRequests) => collect($pullRequests)->pluck('snoozed_until', 'id')->sortKeys()->all() === [
            $snoozed->id => '2026-10-16T10:00:00+00:00',
            $expired->id => null,
        ]));
});
