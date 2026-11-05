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

it('cancels a review that has not finished', function () {
    onboardedAccount();
    $run = ReviewRun::factory()->running()->create();

    $response = $this->from(route('pull-requests.show', $run->pull_request_id))->post(route('review-runs.cancel', $run));

    $response->assertRedirect(route('pull-requests.show', $run->pull_request_id))->assertSessionHas('success', 'AI review cancelled.');
    expect($run->fresh())
        ->status->toBe(AiTaskStatus::Cancelled)
        ->finished_at->not->toBeNull();
});

it('leaves a review that already finished as it was', function () {
    onboardedAccount();
    $run = ReviewRun::factory()->create();

    $this->post(route('review-runs.cancel', $run))->assertRedirect()->assertSessionMissing('success');

    expect($run->fresh()->status)->toBe(AiTaskStatus::Completed);
});

it('starts a new review once the last one was cancelled', function () {
    Queue::fake([RunPullRequestReview::class]);
    onboardedAccount();
    app(AppSettings::class)->set(AppSettings::CLAUDE_AUTH_MODE, ClaudeAuthMode::CliLogin->value);
    $cancelled = ReviewRun::factory()->running()->create(['status' => AiTaskStatus::Cancelled]);

    $this->post(route('pull-requests.review-runs.store', $cancelled->pull_request_id));

    expect(ReviewRun::count())->toBe(2);
    Queue::assertPushed(RunPullRequestReview::class);
});
