<?php

use App\Actions\QueuePullRequestReview;
use App\Enums\AiTaskStatus;
use App\Jobs\RunPullRequestReview;
use App\Models\ReviewRun;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake([RunPullRequestReview::class]);
});

it('keeps waiting for a review that is still queued behind others', function () {
    $queued = ReviewRun::factory()->queued()->create(['created_at' => now()->subHours(3)]);

    $run = app(QueuePullRequestReview::class)->handle($queued->pullRequest);

    expect($run->is($queued))->toBeTrue()
        ->and(ReviewRun::count())->toBe(1);
    Queue::assertNothingPushed();
});

it('does not start a second review while Claude is still working on one', function () {
    $running = ReviewRun::factory()->running()->create([
        'created_at' => now()->subHours(2),
        'started_at' => now()->subMinutes(40),
        'updated_at' => now()->subSeconds(30),
    ]);

    $run = app(QueuePullRequestReview::class)->handle($running->pullRequest);

    expect($run->is($running))->toBeTrue();
    Queue::assertNothingPushed();
});

it('starts a new review when the last one was left behind', function (array $attributes) {
    $stale = ReviewRun::factory()->create($attributes);

    $run = app(QueuePullRequestReview::class)->handle($stale->pullRequest);

    expect($run->is($stale))->toBeFalse()
        ->and($run->status)->toBe(AiTaskStatus::Queued);
    Queue::assertPushedOn('reviews', RunPullRequestReview::class, fn (RunPullRequestReview $job): bool => $job->reviewRun->is($run));
})->with([
    'running for longer than the queue allows' => [['status' => AiTaskStatus::Running, 'started_at' => now()->subMinutes(46), 'updated_at' => now()]],
    'running but no longer showing it is alive' => [['status' => AiTaskStatus::Running, 'started_at' => now()->subMinutes(10), 'updated_at' => now()->subMinutes(6)]],
    'queued for over a day' => [['status' => AiTaskStatus::Queued, 'started_at' => null, 'created_at' => now()->subHours(25)]],
]);
