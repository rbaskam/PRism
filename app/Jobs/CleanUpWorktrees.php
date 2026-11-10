<?php

namespace App\Jobs;

use App\Enums\AiTaskStatus;
use App\Enums\CodeChangeStatus;
use App\Models\CodeChange;
use App\Models\CodeQuestion;
use App\Models\Repository;
use App\Models\ReviewRun;
use App\Services\Git\Git;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Removes what PRism's git work leaves behind when it's interrupted (PRism quit, a job was stopped): worktrees under
 * storage/app/worktrees that nothing uses any more, git's records of them in the linked checkouts, PRism's own
 * prism/change-* branches and the refs/prism/* refs pull requests are fetched into.
 *
 * Changes waiting to be pushed keep their worktree and branch. Reviews and questions don't record their worktree, so
 * one is only removed once it's older than any job runs. Runs on the reviews queue, so it never runs alongside them.
 */
class CleanUpWorktrees implements ShouldQueue
{
    use Queueable;

    /**
     * A worktree this old doesn't belong to a review, question or change that is still running: no job runs longer.
     */
    public const STALE_AFTER_MINUTES = CodeChange::INTERRUPTED_AFTER_MINUTES;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct()
    {
        $this->onQueue(RunPullRequestReview::QUEUE);
    }

    public function handle(Git $git): void
    {
        // Stop removing worktrees in time to finish within the job's limit; the next run carries on.
        $deadline = now()->addSeconds($this->timeout - Git::REMOVE_WORKTREE_TIMEOUT - 60);
        $root = self::normalise(Git::worktreesDirectory());
        $kept = CodeChange::query()->keepingWorktree()->whereNotNull('worktree_path')->pluck('worktree_path')->map(self::normalise(...));
        $keptBranches = CodeChange::query()->keepingWorktree()->whereNotNull('branch')->pluck('branch');
        $staleBefore = now()->subMinutes(self::STALE_AFTER_MINUTES);
        $isLeftOver = fn (string $path): bool => str_starts_with(self::normalise($path), $root.'/')
            && ! $kept->contains(self::normalise($path))
            && ! $this->isRecent($path, $staleBefore);

        $checkouts = Repository::query()->whereNotNull('local_path')->get()
            ->map(fn (Repository $repository): ?string => $repository->localCheckoutPath())
            ->filter()
            ->unique()
            ->values();

        foreach ($checkouts as $checkout) {
            foreach (rescue(fn (): array => $git->worktrees($checkout), [], report: false) as $worktree) {
                if (now()->greaterThan($deadline)) {
                    return;
                }

                if ($isLeftOver($worktree)) {
                    rescue(fn () => $git->removeWorktree($checkout, $worktree), report: false);
                }
            }
        }

        // Directories no linked checkout knows about, e.g. after a repository was unlinked or a worktree half made.
        foreach (File::directories(Git::worktreesDirectory()) as $directory) {
            if (! is_link($directory) && $isLeftOver($directory)) {
                File::deleteDirectory($directory);
            }
        }

        foreach ($checkouts as $checkout) {
            if (now()->greaterThan($deadline)) {
                return;
            }

            $this->cleanUpCheckout($git, $checkout, $keptBranches);
        }
    }

    /**
     * Forget removed worktrees, and delete PRism's branches and fetched pull request heads nothing needs any more.
     *
     * @param  Collection<int, string>  $keptBranches
     */
    private function cleanUpCheckout(Git $git, string $checkout, Collection $keptBranches): void
    {
        try {
            $git->pruneWorktrees($checkout);

            foreach ($git->refs($checkout, 'refs/heads/prism/') as $ref) {
                $branch = substr($ref, strlen('refs/heads/'));

                // A branch still checked out in a worktree can't be deleted, which is fine: it's in use.
                if (preg_match('#^prism/change-\d+$#', $branch) && ! $keptBranches->contains($branch)) {
                    rescue(fn () => $git->deleteBranch($checkout, $branch), report: false);
                }
            }

            if (! $this->isAnythingRunning()) {
                foreach ($git->refs($checkout, 'refs/prism/') as $ref) {
                    rescue(fn () => $git->deleteRef($checkout, $ref), report: false);
                }
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Whether a job might be fetching a pull request right now (one that started on another worker).
     */
    private function isAnythingRunning(): bool
    {
        return ReviewRun::query()->where('status', AiTaskStatus::Running)->exists()
            || CodeQuestion::query()->where('status', AiTaskStatus::Running)->exists()
            || CodeChange::query()->where('status', CodeChangeStatus::Running)->exists();
    }

    private function isRecent(string $path, Carbon $staleBefore): bool
    {
        $modifiedAt = @filemtime($path);

        return $modifiedAt !== false && $modifiedAt >= $staleBefore->getTimestamp();
    }

    /**
     * A path to compare with others: symlinks resolved (e.g. /var and /private/var on macOS), forward slashes, and
     * lower case on Windows, whose paths ignore case.
     */
    private static function normalise(string $path): string
    {
        $normalised = rtrim(str_replace('\\', '/', realpath($path) ?: $path), '/');

        return PHP_OS_FAMILY === 'Windows' ? strtolower($normalised) : $normalised;
    }
}
