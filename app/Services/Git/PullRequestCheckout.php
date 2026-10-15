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
     * @template TResult
     *
     * @param  Closure(?string): TResult  $callback
     * @return TResult
     */
    public function using(PullRequest $pullRequest, Closure $callback): mixed
    {
        $checkout = $pullRequest->repository->localCheckoutPath();
        $token = GithubAccount::current()?->token;
        $worktree = null;

        if ($checkout !== null && $token !== null) {
            try {
                $commit = $this->git->fetchPullRequestHead($checkout, $pullRequest->repository->full_name, $pullRequest->number, $token);
                $worktree = $this->git->worktreePath($pullRequest->repository->full_name, "pr-{$pullRequest->number}-review");
                $this->git->addWorktree($checkout, $worktree, $commit);
            } catch (Throwable $exception) {
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
