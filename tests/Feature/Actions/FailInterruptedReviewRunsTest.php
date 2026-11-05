<?php

use App\Actions\FailInterruptedReviewRuns;
use App\Enums\AiTaskStatus;
use App\Enums\AlertType;
use App\Models\Alert;
use App\Models\ReviewRun;

it('marks a review as failed when it has not shown it is still going for a few minutes', function () {
    $interrupted = ReviewRun::factory()->running()->create();
    $this->travel(ReviewRun::INTERRUPTED_AFTER_MINUTES * 60 + 1)->seconds();
    $stillGoing = ReviewRun::factory()->running()->create(['started_at' => now()->subHour()]);
    $queued = ReviewRun::factory()->queued()->create(['created_at' => now()->subHour(), 'updated_at' => now()->subHour()]);

    expect(app(FailInterruptedReviewRuns::class)->handle())->toBe(1);

    expect($interrupted->fresh())
        ->status->toBe(AiTaskStatus::Failed)
        ->error->toBe(FailInterruptedReviewRuns::ERROR)
        ->finished_at->not->toBeNull();
    expect($stillGoing->fresh()->status)->toBe(AiTaskStatus::Running);
    expect($queued->fresh()->status)->toBe(AiTaskStatus::Queued);
    expect(Alert::sole())
        ->type->toBe(AlertType::ReviewFinished)
        ->title->toBe('AI review stopped')
        ->url->toBe("/pull-requests/{$interrupted->pull_request_id}?tab=findings");
});
