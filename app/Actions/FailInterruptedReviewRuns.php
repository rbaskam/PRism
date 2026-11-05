<?php

namespace App\Actions;

use App\Enums\AiTaskStatus;
use App\Enums\AlertType;
use App\Models\ReviewRun;
use App\Services\Alerts;

/**
 * When PRism quits while Claude is reviewing, Claude stops with it but the run is still marked as running, so the pull
 * request would show "Claude is reviewing…" until the queue gave up on it half an hour later. A running review touches
 * its run every 30 seconds, so one that hasn't for a few minutes is marked as failed, and the user can run it again.
 */
class FailInterruptedReviewRuns
{
    public const ERROR = 'PRism was closed (or stopped responding) before Claude finished this review.';

    public function __construct(private readonly Alerts $alerts) {}

    /**
     * @return int How many runs were marked as failed.
     */
    public function handle(): int
    {
        $runs = ReviewRun::query()->interrupted()->with('pullRequest.repository')->get();

        foreach ($runs as $run) {
            $run->update(['status' => AiTaskStatus::Failed, 'error' => self::ERROR, 'finished_at' => now()]);

            $this->alerts->raise(
                AlertType::ReviewFinished,
                'AI review stopped',
                "{$run->pullRequest->reference()}: PRism was closed before Claude finished.",
                Alerts::pullRequestUrl($run->pullRequest, 'findings'),
                $run->pullRequest,
            );
        }

        return $runs->count();
    }
}
