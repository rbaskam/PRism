<?php

namespace App\Jobs;

use App\Enums\CodeChangeStatus;
use App\Models\CodeChange;
use App\Services\Git\Git;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Removes the worktree and local branch of changes that were pushed or discarded. Runs in the queue because git must
 * never run during a request. Anything left behind is removed later by CleanUpWorktrees.
 */
class RemoveCodeChangeWorktree implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = Git::REMOVE_WORKTREE_TIMEOUT + 60;

    public function __construct(public CodeChange $codeChange)
    {
        // Removing a big worktree can take longer than the default worker allows.
        $this->onQueue(RunPullRequestReview::QUEUE);
    }

    public function handle(Git $git): void
    {
        $change = $this->codeChange->fresh();

        if ($change === null || $change->worktree_path === null || ! in_array($change->status, [CodeChangeStatus::Pushed, CodeChangeStatus::Discarded], true)) {
            return;
        }

        $git->removeWorktree($change->checkout_path, $change->worktree_path, $change->branch);

        $change->update(['worktree_path' => null]);
    }
}
