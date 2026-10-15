<?php

namespace App\Jobs;

use App\Enums\AiTaskStatus;
use App\Models\GithubAccount;
use App\Models\ReplyDraft;
use App\Services\Claude\ClaudeCode;
use App\Services\Claude\ClaudeException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Asks Claude to draft a reply to a pull request comment. The user edits and sends it themselves.
 */
class DraftCommentReply implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    /**
     * @param  list<array{author: string, body: string}>  $conversation
     */
    public function __construct(
        public ReplyDraft $draft,
        public array $conversation,
        public ?string $path = null,
        public ?string $diffHunk = null,
    ) {
        $this->onQueue(RunPullRequestReview::QUEUE);
    }

    public function handle(ClaudeCode $claude): void
    {
        $pullRequest = $this->draft->pullRequest->load('repository');
        $account = GithubAccount::current();
        $this->draft->update(['status' => AiTaskStatus::Running]);

        $result = $claude->run(
            prompt: $this->prompt($pullRequest->repository->full_name, $pullRequest->number, $account?->login),
            jsonSchema: [
                'type' => 'object',
                'properties' => ['reply' => ['type' => 'string']],
                'required' => ['reply'],
            ],
            systemPrompt: 'You draft replies to GitHub pull request comments for a developer. This is read-only: never post, comment, approve or change anything. Return only the reply text in GitHub markdown.',
            workingDirectory: $pullRequest->repository->localCheckoutPath(),
            githubToken: $account?->token,
            timeoutSeconds: 540,
        );

        $reply = $result->structuredOutput['reply'] ?? throw new ClaudeException('Claude did not return a reply.');

        $this->draft->update(['status' => AiTaskStatus::Completed, 'body' => trim($reply)]);
    }

    public function failed(?Throwable $exception): void
    {
        $this->draft->update([
            'status' => AiTaskStatus::Failed,
            'error' => $exception?->getMessage() ?? 'Drafting the reply stopped unexpectedly.',
        ]);
    }

    private function prompt(string $repository, int $number, ?string $myLogin): string
    {
        $lines = [
            "Draft a reply from @{$myLogin} to the latest comment in this discussion on pull request {$repository}#{$number}.",
            "If you need more context, read the pull request with `gh pr view {$number} --repo {$repository}` and `gh pr diff {$number} --repo {$repository}`.",
        ];

        if ($this->path !== null) {
            $lines[] = "The discussion is about `{$this->path}`".($this->diffHunk ? ":\n```diff\n{$this->diffHunk}\n```" : '.');
        }

        $lines[] = "\nDiscussion so far:";

        foreach ($this->conversation as $comment) {
            $lines[] = "@{$comment['author']}: {$comment['body']}";
        }

        if (filled($this->draft->instructions)) {
            $lines[] = "\nHow @{$myLogin} wants to reply: {$this->draft->instructions}";
        }

        $lines[] = "\nKeep the reply concise, friendly and technically precise.";

        return implode("\n", $lines);
    }
}
