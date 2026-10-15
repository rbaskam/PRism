<?php

namespace App\Jobs;

use App\Enums\CodeChangeStatus;
use App\Models\CodeChange;
use App\Models\GithubAccount;
use App\Models\ReviewFinding;
use App\Services\AppSettings;
use App\Services\Claude\ClaudeCode;
use App\Services\DesktopNotifier;
use App\Services\Git\Git;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Lets Claude change a pull request's code in a worktree and commit the result locally.
 * Nothing is pushed until the user has looked at the diff and approved it.
 */
class MakeCodeChanges implements ShouldQueue
{
    use Queueable;

    /**
     * Diffs larger than this are cut short in the database; the full change is still in the worktree.
     */
    private const MAX_STORED_DIFF_BYTES = 1_000_000;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public CodeChange $codeChange)
    {
        $this->onQueue(RunPullRequestReview::QUEUE);
    }

    public function handle(ClaudeCode $claude, Git $git, AppSettings $settings, DesktopNotifier $notifier): void
    {
        $change = $this->codeChange;
        $pullRequest = $change->pullRequest->load('repository');
        $repository = $pullRequest->repository->full_name;
        $account = GithubAccount::current() ?? throw new \RuntimeException('Connect GitHub before asking Claude to change code.');

        $change->update(['status' => CodeChangeStatus::Running, 'started_at' => now(), 'error' => null]);

        $base = $git->fetchPullRequestHead($change->checkout_path, $repository, $pullRequest->number, $account->token);
        $branch = "prism/change-{$change->id}";
        $worktree = $git->worktreePath($repository, "pr-{$pullRequest->number}-change-{$change->id}");
        $git->addWorktree($change->checkout_path, $worktree, $base, $branch);
        $change->update(['worktree_path' => $worktree, 'branch' => $branch, 'base_sha' => $base]);

        $result = $claude->run(
            prompt: $this->prompt($repository),
            jsonSchema: [
                'type' => 'object',
                'properties' => [
                    'summary' => ['type' => 'string', 'description' => 'What you changed and why, in GitHub markdown.'],
                    'commit_message' => ['type' => 'string', 'description' => 'A conventional commit message for any changes left uncommitted.'],
                ],
                'required' => ['summary', 'commit_message'],
            ],
            systemPrompt: 'You are changing code in a git worktree of a pull request for the developer who owns it. Edit files and make local commits only. Never push, never change git remotes or configuration, and never touch files outside the working directory.',
            workingDirectory: $worktree,
            githubToken: $account->token,
            allowedTools: ClaudeCode::EDITING_COMMANDS,
            timeoutSeconds: 1700,
            tools: ClaudeCode::EDITING_TOOLS,
        );

        if ($git->hasUncommittedChanges($worktree)) {
            $git->commitAll(
                $worktree,
                Str::limit((string) ($result->structuredOutput['commit_message'] ?? 'Apply review fixes'), 500, ''),
                $account->name ?? $account->login,
                "{$account->github_id}+{$account->login}@users.noreply.github.com",
            );
        }

        $commits = $git->commitsSince($worktree, $base);

        if ($commits === []) {
            $git->removeWorktree($change->checkout_path, $worktree, $branch);
        }

        $diff = $commits === [] ? ['diff' => '', 'files_changed' => 0, 'additions' => 0, 'deletions' => 0] : $git->diffSince($worktree, $base);

        $change->update([
            'status' => $commits === [] ? CodeChangeStatus::NoChanges : CodeChangeStatus::Ready,
            'head_sha' => $commits === [] ? null : trim($git->run($worktree, ['rev-parse', 'HEAD'])->output()),
            'summary' => $result->structuredOutput['summary'] ?? $result->text,
            'commits' => $commits,
            'diff' => Str::limit($diff['diff'], self::MAX_STORED_DIFF_BYTES, "\n… diff truncated …"),
            'files_changed' => $diff['files_changed'],
            'additions' => $diff['additions'],
            'deletions' => $diff['deletions'],
            'cost_usd' => $result->costUsd,
            'duration_ms' => $result->durationMs,
        ]);

        if ($settings->notifiesWhenReviewFinishes()) {
            $notifier->send(
                $commits === [] ? 'Claude made no changes' : "Claude's changes are ready to review",
                $pullRequest->reference(),
                DesktopNotifier::PULL_REQUEST_REFERENCE.$pullRequest->id,
            );
        }
    }

    public function failed(?Throwable $exception): void
    {
        $change = $this->codeChange->fresh();

        if ($change->worktree_path !== null) {
            rescue(fn () => app(Git::class)->removeWorktree($change->checkout_path, $change->worktree_path, $change->branch), report: false);
        }

        $change->update([
            'status' => CodeChangeStatus::Failed,
            'error' => $exception?->getMessage() ?? 'Claude stopped unexpectedly.',
        ]);
    }

    private function prompt(string $repository): string
    {
        $change = $this->codeChange;
        $pullRequest = $change->pullRequest;
        $lines = [
            "You are working on pull request {$repository}#{$pullRequest->number} \"{$pullRequest->title}\" ({$pullRequest->head_ref} into {$pullRequest->base_ref}).",
            'The working directory is a git worktree at the head of the pull request.',
            "Read it with `gh pr view {$pullRequest->number} --repo {$repository}` and `gh pr diff {$pullRequest->number} --repo {$repository}` if you need more context.",
        ];

        if (filled($change->instructions)) {
            $lines[] = "\nWhat the developer wants:\n{$change->instructions}";
        }

        $findings = ReviewFinding::query()->whereKey($change->finding_ids ?? [])->where('pull_request_id', $pullRequest->id)->get();

        if ($findings->isNotEmpty()) {
            $lines[] = "\nFix these review findings:";

            foreach ($findings as $finding) {
                $location = $finding->path ? " ({$finding->path}".($finding->line ? ":{$finding->line}" : '').')' : '';
                $lines[] = "- {$finding->title}{$location}: {$finding->body}".($finding->suggestion ? " Suggested fix: {$finding->suggestion}" : '');
            }
        }

        if (filled($change->comments)) {
            $lines[] = "\nAddress these review comments:";

            foreach ($change->comments as $comment) {
                $location = isset($comment['path']) ? " on {$comment['path']}".(isset($comment['line']) ? ":{$comment['line']}" : '') : '';
                $lines[] = "- @{$comment['author']}{$location}: {$comment['body']}";
            }
        }

        $lines[] = "\nMake focused, minimal changes that match the code style around them, then commit them with clear conventional commit messages (for example `fix: handle missing customer`). Do not push. Summarise what you changed.";

        return implode("\n", $lines);
    }
}
