<?php

use App\Models\PullRequest;
use App\Models\ReviewRun;
use Inertia\Testing\AssertableInertia as Assert;

it('lists the pull requests Claude is reviewing so the sidebar can link to them', function () {
    onboardedAccount();
    $running = PullRequest::factory()->create(['number' => 42, 'title' => 'Add rate limiting']);
    $waiting = PullRequest::factory()->create(['number' => 43, 'title' => 'Cache the dashboard']);
    ReviewRun::factory()->running()->for($running)->create();
    ReviewRun::factory()->queued()->for($waiting)->create(['created_at' => now()->subHours(2)]);
    ReviewRun::factory()->create();
    ReviewRun::factory()->running()->create(['started_at' => now()->subHours(2)]);

    $response = $this->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('counts.active_reviews', 2)
        ->where('counts.reviewing', [
            [
                'pull_request_id' => $running->id,
                'reference' => $running->repository->full_name.'#42',
                'title' => 'Add rate limiting',
                'status' => 'running',
            ],
            [
                'pull_request_id' => $waiting->id,
                'reference' => $waiting->repository->full_name.'#43',
                'title' => 'Cache the dashboard',
                'status' => 'queued',
            ],
        ]));
});
