<?php

use App\Enums\AiTaskStatus;
use App\Models\PullRequest;
use App\Models\ReviewRun;
use Inertia\Testing\AssertableInertia as Assert;

it('lists the pull requests Claude is reviewing so the sidebar can link to them', function () {
    onboardedAccount();
    $pullRequest = PullRequest::factory()->create(['number' => 42, 'title' => 'Add rate limiting']);
    ReviewRun::factory()->queued()->for($pullRequest)->create(['status' => AiTaskStatus::Running]);
    ReviewRun::factory()->create();
    ReviewRun::factory()->queued()->create(['created_at' => now()->subHours(2)]);

    $response = $this->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('counts.active_reviews', 1)
        ->where('counts.reviewing', [[
            'pull_request_id' => $pullRequest->id,
            'reference' => $pullRequest->repository->full_name.'#42',
            'title' => 'Add rate limiting',
            'status' => 'running',
        ]]));
});
