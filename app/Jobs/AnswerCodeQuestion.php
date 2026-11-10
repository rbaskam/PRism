<?php

namespace App\Jobs;

use App\Enums\AiTaskStatus;
use App\Enums\AlertType;
use App\Models\CodeQuestion;
use App\Models\GithubAccount;
use App\Services\Alerts;
use App\Services\Claude\ClaudeCode;
use App\Services\Claude\ClaudeException;
use App\Services\Git\Git;
use App\Services\Git\PullRequestCheckout;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Asks Claude a question about lines the user highlighted in a pull request.
 */
class AnswerCodeQuestion implements ShouldQueue
{
    use Queueable;

    /**
     * Claude may answer for up to 14 minutes.
     */
    public const CLAUDE_TIMEOUT = 840;

    /**
     * Kept back at the end of the job for saving the answer.
     */
    public const SAVING_SECONDS = 60;

    public int $tries = 1;

    /**
     * Preparing the worktree, Claude's answer and removing the worktree all fit in this: Claude gets what's left.
     */
    public int $timeout = 1200;

    public function __construct(public CodeQuestion $question)
    {
        $this->onQueue(RunPullRequestReview::QUEUE);
    }

    public function handle(ClaudeCode $claude, PullRequestCheckout $checkout, Alerts $alerts): void
    {
        $question = $this->question;
        $pullRequest = $question->pullRequest->load('repository');
        $account = GithubAccount::current();
        $startedAt = hrtime(true);
        $deadline = now()->addSeconds($this->timeout - self::SAVING_SECONDS);

        $question->update(['status' => AiTaskStatus::Running, 'started_at' => now()]);

        $result = $checkout->using($pullRequest, fn (?string $worktree) => $claude->run(
            prompt: $this->prompt($account?->login),
            jsonSchema: [
                'type' => 'object',
                'properties' => [
                    'answer' => ['type' => 'string', 'description' => 'The answer in GitHub markdown.'],
                    'suggested_code' => ['type' => ['string', 'null'], 'description' => 'Complete replacement for the highlighted lines, or null.'],
                ],
                'required' => ['answer', 'suggested_code'],
            ],
            systemPrompt: 'You answer questions about code in a GitHub pull request for a developer reviewing it. This is read-only: never comment on, approve, edit or push to the pull request or repository.',
            workingDirectory: $worktree,
            githubToken: $account?->token,
            timeoutSeconds: ClaudeCode::timeoutBefore(
                $worktree === null ? $deadline : $deadline->copy()->subSeconds(Git::REMOVE_WORKTREE_TIMEOUT),
                self::CLAUDE_TIMEOUT,
            ),
            repository: $pullRequest->repository->full_name,
        ), $question->head_sha);

        $answer = $result->structuredOutput['answer'] ?? throw new ClaudeException('Claude did not return an answer.');

        $question->update([
            'status' => AiTaskStatus::Completed,
            'answer' => trim($answer),
            'suggested_code' => filled($result->structuredOutput['suggested_code'] ?? null) ? $result->structuredOutput['suggested_code'] : null,
            'cost_usd' => $result->costUsd,
            'duration_ms' => $result->durationMs ?? (int) ((hrtime(true) - $startedAt) / 1_000_000),
        ]);

        $alerts->raise(AlertType::ReviewFinished, 'Claude answered your question', $question->location(), Alerts::pullRequestUrl($pullRequest, 'questions'), $pullRequest);
    }

    public function failed(?Throwable $exception): void
    {
        $this->question->update([
            'status' => AiTaskStatus::Failed,
            'error' => $exception?->getMessage() ?? 'Claude stopped unexpectedly.',
        ]);
    }

    private function prompt(?string $myLogin): string
    {
        $question = $this->question;
        $pullRequest = $question->pullRequest;
        $repository = $pullRequest->repository->full_name;
        $version = $question->side === CodeQuestion::SIDE_OLD ? 'the old version (lines removed by the pull request)' : 'the new version';

        $lines = [
            "@{$myLogin} is reviewing pull request {$repository}#{$pullRequest->number} \"{$pullRequest->title}\".",
            "They highlighted lines {$question->start_line}-{$question->end_line} of `{$question->path}` in {$version}:",
            '```diff',
            $question->selected_code,
            '```',
        ];

        $earlier = $question->parent_id === null
            ? collect()
            : CodeQuestion::query()
                ->where(fn ($query) => $query->whereKey($question->parent_id)->orWhere('parent_id', $question->parent_id))
                ->whereKeyNot($question->id)
                ->where('status', AiTaskStatus::Completed)
                ->orderBy('id')
                ->get();

        if ($earlier->isNotEmpty()) {
            $lines[] = "\nEarlier in this conversation:";

            foreach ($earlier as $previous) {
                $lines[] = "Q: {$previous->prompt}\nA: {$previous->answer}";
            }
        }

        array_push(
            $lines,
            "\nTheir question: {$question->prompt}",
            '',
            "For more context, read the pull request with `gh pr view {$pullRequest->number}` and `gh pr diff {$pullRequest->number}` (gh is already set up for {$repository}, so don't pass --repo), or the files in the working directory if it is a checkout of the pull request.",
            'Answer concisely and specifically in GitHub markdown. If you recommend rewriting the highlighted lines, put the complete replacement code (no diff markers) in suggested_code; otherwise set it to null.',
        );

        return implode("\n", $lines);
    }
}
