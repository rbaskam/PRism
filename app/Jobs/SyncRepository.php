<?php

namespace App\Jobs;

use App\Actions\QueuePullRequestReview;
use App\Actions\SyncRepositoryPullRequests;
use App\Enums\AlertType;
use App\Models\GithubAccount;
use App\Models\PullRequest;
use App\Models\Repository;
use App\Services\Alerts;
use App\Services\AppSettings;
use App\Services\DesktopNotifier;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Syncs one repository's open pull requests and starts automatic reviews when the user has enabled them.
 */
class SyncRepository implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 120;

    public function __construct(public Repository $repository) {}

    public function uniqueId(): string
    {
        return (string) $this->repository->id;
    }

    public function handle(SyncRepositoryPullRequests $sync, QueuePullRequestReview $queueReview, AppSettings $settings, DesktopNotifier $notifier, Alerts $alerts): void
    {
        $account = GithubAccount::current();

        if ($account === null || ! $this->repository->is_tracked) {
            return;
        }

        $result = $sync->handle($this->repository, $account);

        $result['newly_opened']->each(fn (PullRequest $pullRequest) => $alerts->raise(
            AlertType::NewPullRequests,
            AlertType::NewPullRequests->title(''),
            "{$pullRequest->reference()}: {$pullRequest->title}",
            Alerts::pullRequestUrl($pullRequest),
            $pullRequest,
        ));

        if ($settings->reviewsAutomaticallyWhenRequested() && $settings->isClaudeConfigured()) {
            $result['newly_requested']
                ->reject(fn (PullRequest $pullRequest): bool => $pullRequest->is_draft)
                ->each(fn (PullRequest $pullRequest) => $queueReview->handle($pullRequest));
        }

        $notifier->setBadgeCount(PullRequest::query()->awaitingMyReview()->count());
    }
}
