<?php

namespace App\Actions;

use App\Enums\AiTaskStatus;
use App\Jobs\RunPullRequestReview;
use App\Models\PullRequest;
use App\Models\ReviewRun;
use App\Services\AppSettings;
use App\Services\Claude\PullRequestReviewPrompt;
use Illuminate\Database\Eloquent\Builder;

/**
 * Starts a Claude review of a pull request unless one is already in progress.
 */
class QueuePullRequestReview
{
    /**
     * A run still marked as running this long after it started was interrupted: the queue stops a review after 30 minutes.
     */
    public const STALE_AFTER_MINUTES = 45;

    /**
     * The reviews worker runs one review at a time, so a run can wait a long while for its turn and still be on its way.
     * One that has waited this long was lost, e.g. its job was removed from the queue, and the user can start another.
     */
    public const QUEUED_FOR_AT_MOST_HOURS = 24;

    public function __construct(
        private readonly PullRequestReviewPrompt $prompt,
        private readonly AppSettings $settings,
    ) {}

    public function handle(PullRequest $pullRequest): ReviewRun
    {
        $activeRun = $pullRequest->reviewRuns()
            ->where(self::whereActive(...))
            ->latest('id')
            ->first();

        if ($activeRun !== null) {
            return $activeRun;
        }

        $run = $pullRequest->reviewRuns()->create([
            'status' => AiTaskStatus::Queued,
            'head_sha' => $pullRequest->head_sha,
            'model' => $this->settings->claudeModel(),
            'prompt' => $this->prompt->prompt($pullRequest),
        ]);

        RunPullRequestReview::dispatch($run);

        return $run;
    }

    /**
     * Limit a query to runs that are still in progress: waiting for the reviews worker, or running and still showing
     * they're alive (see ReviewRun::scopeInterrupted() for runs left behind when PRism quit).
     *
     * @param  Builder<ReviewRun>  $query
     */
    public static function whereActive(Builder $query): void
    {
        $query->where(fn (Builder $queued): Builder => $queued
            ->where('status', AiTaskStatus::Queued)
            ->where('created_at', '>=', now()->subHours(self::QUEUED_FOR_AT_MOST_HOURS)))
            ->orWhere(fn (Builder $running): Builder => $running
                ->where('status', AiTaskStatus::Running)
                ->where('started_at', '>=', now()->subMinutes(self::STALE_AFTER_MINUTES))
                ->where('updated_at', '>=', now()->subMinutes(ReviewRun::INTERRUPTED_AFTER_MINUTES)));
    }
}
