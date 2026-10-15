<?php

namespace App\Jobs;

use App\Actions\RecordReviewResult;
use App\Enums\AiTaskStatus;
use App\Enums\AlertType;
use App\Models\GithubAccount;
use App\Models\ReviewRun;
use App\Services\Alerts;
use App\Services\Claude\ClaudeCode;
use App\Services\Claude\ClaudeException;
use App\Services\Claude\PullRequestReviewPrompt;
use App\Services\Git\PullRequestCheckout;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Asks Claude Code to review a pull request and records what it found.
 */
class RunPullRequestReview implements ShouldQueue
{
    use Queueable;

    public const QUEUE = 'reviews';

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public ReviewRun $reviewRun)
    {
        $this->onQueue(self::QUEUE);
    }

    public function handle(
        ClaudeCode $claude,
        PullRequestCheckout $checkout,
        PullRequestReviewPrompt $prompt,
        RecordReviewResult $recordResult,
        Alerts $alerts,
    ): void {
        $run = $this->reviewRun;
        $pullRequest = $run->pullRequest->load('repository');

        $run->update(['status' => AiTaskStatus::Running, 'started_at' => now(), 'error' => null]);

        $result = $checkout->using($pullRequest, fn (?string $worktree) => $claude->run(
            prompt: $run->prompt,
            jsonSchema: PullRequestReviewPrompt::schema(),
            systemPrompt: $prompt->systemPrompt($pullRequest, $pullRequest->findings()->whereNull('resolved_at')->get(), $worktree !== null),
            workingDirectory: $worktree,
            githubToken: GithubAccount::current()?->token,
        ));

        if ($result->structuredOutput === null) {
            throw new ClaudeException('Claude finished without returning review findings. '.Str::limit($result->text, 300));
        }

        $recordResult->handle($run, $result->structuredOutput);

        $run->update([
            'status' => AiTaskStatus::Completed,
            'raw_output' => $result->rawOutput,
            'cost_usd' => $result->costUsd,
            'duration_ms' => $result->durationMs,
            'session_id' => $result->sessionId,
            'finished_at' => now(),
        ]);

        $alerts->raise(
            AlertType::ReviewFinished,
            'AI review finished',
            "{$pullRequest->reference()}: ".trans_choice('{0} no new findings|{1} 1 new finding|[2,*] :count new findings', $run->new_findings_count),
            Alerts::pullRequestUrl($pullRequest, 'findings'),
            $pullRequest,
        );
    }

    public function failed(?Throwable $exception): void
    {
        $this->reviewRun->update([
            'status' => AiTaskStatus::Failed,
            'error' => $exception?->getMessage() ?? 'The review stopped unexpectedly.',
            'finished_at' => now(),
        ]);

        $pullRequest = $this->reviewRun->pullRequest;

        app(Alerts::class)->raise(AlertType::ReviewFinished, 'AI review failed', $pullRequest->reference(), Alerts::pullRequestUrl($pullRequest, 'history'), $pullRequest);
    }
}
