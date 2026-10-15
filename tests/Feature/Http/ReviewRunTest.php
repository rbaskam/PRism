<?php

use App\Enums\AiTaskStatus;
use App\Enums\ClaudeAuthMode;
use App\Jobs\RunPullRequestReview;
use App\Models\PullRequest;
use App\Models\ReviewRun;
use App\Services\AppSettings;
use Illuminate\Support\Facades\Queue;

it('queues an AI review with the rendered review prompt', function () {
    Queue::fake([RunPullRequestReview::class]);
    onboardedAccount();
    app(AppSettings::class)->set(AppSettings::CLAUDE_AUTH_MODE, ClaudeAuthMode::CliLogin->value);
    app(AppSettings::class)->set(AppSettings::REVIEW_PROMPT, 'Review {repository}#{number} please');
    $pullRequest = PullRequest::factory()->create(['number' => 1578]);

    $response = $this->post(route('pull-requests.review-runs.store', $pullRequest));

    $response->assertRedirect()->assertSessionHas('success');
    $run = ReviewRun::sole();
    expect($run)
        ->status->toBe(AiTaskStatus::Queued)
        ->prompt->toBe("Review {$pullRequest->repository->full_name}#1578 please")
        ->head_sha->toBe($pullRequest->head_sha);
    Queue::assertPushedOn('reviews', RunPullRequestReview::class, fn (RunPullRequestReview $job): bool => $job->reviewRun->is($run));
});

it('does not start a second review while one is running', function () {
    Queue::fake([RunPullRequestReview::class]);
    onboardedAccount();
    app(AppSettings::class)->set(AppSettings::CLAUDE_AUTH_MODE, ClaudeAuthMode::CliLogin->value);
    $running = ReviewRun::factory()->queued()->create();

    $this->post(route('pull-requests.review-runs.store', $running->pull_request_id));

    expect(ReviewRun::count())->toBe(1);
    Queue::assertNothingPushed();
});

it('asks the user to connect Claude before running a review', function () {
    Queue::fake([RunPullRequestReview::class]);
    onboardedAccount();
    $pullRequest = PullRequest::factory()->create();

    $response = $this->post(route('pull-requests.review-runs.store', $pullRequest));

    $response->assertSessionHas('error', 'Connect Claude in Settings → Claude to run AI reviews.');
    expect(ReviewRun::count())->toBe(0);
    Queue::assertNothingPushed();
});
