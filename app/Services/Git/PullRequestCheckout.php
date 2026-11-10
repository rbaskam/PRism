<?php

namespace App\Services\Git;

use App\Models\GithubAccount;
use App\Models\PullRequest;
use Closure;
use Throwable;

/**
 * Gives Claude the exact code of a pull request: a temporary worktree of the user's local checkout
 * at the pull request's head commit, removed again afterwards.
 */
class PullRequestCheckout
{
    public function __construct(private readonly Git $git) {}

    /**
     * Run the callback with the path of a worktree at the pull request's head, or null when there is
     * no local checkout or it could not be prepared (the callback then works from the diff alone).
     *
     * The worktree is only made at $headSha (the pull request's synced head unless given): when GitHub
     * has a different head, Claude works from the diff instead of code the user hasn't seen.
     *
     * Allow Git::PREPARE_WORKTREE_TIMEOUT before the callback and Git::REMOVE_WORKTREE_TIMEOUT after it.
     *
     * @template TResult
     *
     * @param  Closure(?string): TResult  $callback
     * @return TResult
     */
    public function using(PullRequest $pullRequest, Closure $callback, ?string $headSha = null): mixed
    {
        $checkout = $pullRequest->repository->localCheckoutPath();
        $token = GithubAccount::current()?->token;
        $worktree = null;

        if ($checkout !== null && $token !== null) {
            try {
                $commit = $this->git->fetchPullRequestHead(
                    $checkout,
                    $pullRequest->repository->full_name,
                    $pullRequest->number,
                    $token,
                    $headSha ?? $pullRequest->head_sha,
                );
                $worktree = $this->git->worktreePath($pullRequest->repository->full_name, "pr-{$pullRequest->number}-review");
                $this->git->addWorktree($checkout, $worktree, $commit);
            } catch (Throwable $exception) {
                // A half-made worktree is left for CleanUpWorktrees, so this never takes longer than planned.
                report($exception);
                $worktree = null;
            }
        }

        try {
            return $callback($worktree);
        } finally {
            if ($worktree !== null) {
                rescue(fn () => $this->git->removeWorktree($checkout, $worktree), report: false);
            }
        }
    }
}
