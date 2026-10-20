<?php

namespace App\Jobs;

use App\Enums\CodeChangeStatus;
use App\Enums\CodeChangeTarget;
use App\Models\CodeChange;
use App\Models\GithubAccount;
use App\Services\Git\Git;
use App\Services\Github\GithubClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Pushes approved changes to GitHub: onto the pull request's branch, or to a new branch with its own pull request.
 */
class PushCodeChange implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public CodeChange $codeChange) {}

    public function handle(Git $git, GithubClient $github): void
    {
        $change = $this->codeChange;
        $pullRequest = $change->pullRequest->load('repository');
        $repository = $pullRequest->repository->full_name;
        $account = GithubAccount::current() ?? throw new \RuntimeException('Connect GitHub before pushing.');
        $destination = $change->destinationBranch();

        $git->push($change->worktree_path, $repository, $destination, $account->token);

        $createdUrl = null;

        if ($change->target === CodeChangeTarget::NewPullRequest) {
            $created = $github->createPullRequest(
                $repository,
                "Fixes for #{$pullRequest->number}: {$pullRequest->title}",
                $destination,
                (string) $pullRequest->head_ref,
                trim(($change->summary ?? '')."\n\nChanges for #{$pullRequest->number}, made with Claude Code in PRism."),
            );
            $createdUrl = $created['html_url'] ?? null;
        }

        $change->update([
            'status' => CodeChangeStatus::Pushed,
            'pushed_at' => now(),
            'pushed_branch' => $destination,
            'created_pull_request_url' => $createdUrl,
            'error' => null,
        ]);

        rescue(fn () => $git->removeWorktree($change->checkout_path, $change->worktree_path, $change->branch), report: false);
    }

    /**
     * Keep the changes so the user can try again (for example after pulling new commits) or discard them.
     */
    public function failed(?Throwable $exception): void
    {
        $this->codeChange->update([
            'status' => CodeChangeStatus::Ready,
            'error' => 'Push failed: '.($exception ? GithubClient::describeFailure($exception) : 'unknown error'),
        ]);
    }
}
