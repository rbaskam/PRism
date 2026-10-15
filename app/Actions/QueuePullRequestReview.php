<?php

namespace App\Actions;

use App\Enums\AiTaskStatus;
use App\Jobs\RunPullRequestReview;
use App\Models\PullRequest;
use App\Models\ReviewRun;
use App\Services\AppSettings;
use App\Services\Claude\PullRequestReviewPrompt;

/**
 * Starts a Claude review of a pull request unless one is already in progress.
 */
class QueuePullRequestReview
{
    /**
     * A run still marked active after this long was interrupted (for example the app was quit), so a new one may start.
     */
    public const STALE_AFTER_MINUTES = 45;

    public function __construct(
        private readonly PullRequestReviewPrompt $prompt,
        private readonly AppSettings $settings,
    ) {}

    public function handle(PullRequest $pullRequest): ReviewRun
    {
        $activeRun = $pullRequest->reviewRuns()
            ->whereIn('status', [AiTaskStatus::Queued, AiTaskStatus::Running])
            ->where('created_at', '>=', now()->subMinutes(self::STALE_AFTER_MINUTES))
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
}
