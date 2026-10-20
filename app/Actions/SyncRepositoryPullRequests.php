<?php

namespace App\Actions;

use App\Models\GithubAccount;
use App\Models\PullRequest;
use App\Models\Repository;
use App\Services\Github\GithubClient;
use App\Services\Github\PullRequestMapper;
use Illuminate\Support\Collection;

/**
 * Pulls a repository's open pull requests from GitHub and closes any that are no longer open.
 */
class SyncRepositoryPullRequests
{
    public function __construct(
        private readonly GithubClient $github,
        private readonly PullRequestMapper $mapper,
    ) {}

    /**
     * Returns the pull requests that newly need the user's review, and the ones someone else opened since the last sync.
     *
     * @return array{newly_requested: Collection<int, PullRequest>, newly_opened: Collection<int, PullRequest>}
     */
    public function handle(Repository $repository, GithubAccount $account): array
    {
        $wasRequested = $repository->pullRequests()->open()->where('is_review_requested_from_me', true)->pluck('number');
        $isFirstSync = $repository->synced_at === null;
        $seenIds = [];
        $newlyRequested = collect();
        $newlyOpened = collect();

        foreach ($this->github->openPullRequests($repository->owner, $repository->name) as $node) {
            $pullRequest = $repository->pullRequests()->updateOrCreate(
                ['number' => $node['number']],
                $this->mapper->attributes($node, $account),
            );

            $seenIds[] = $pullRequest->id;

            if ($pullRequest->is_review_requested_from_me && ! $wasRequested->contains($pullRequest->number)) {
                $newlyRequested->push($pullRequest);
            }

            if ($pullRequest->wasRecentlyCreated && ! $isFirstSync && ! $pullRequest->is_authored_by_me) {
                $newlyOpened->push($pullRequest);
            }
        }

        $repository->pullRequests()
            ->open()
            ->whereNotIn('id', $seenIds)
            ->update(['state' => PullRequest::STATE_CLOSED, 'closed_at' => now(), 'is_review_requested_from_me' => false]);

        $repository->update(['synced_at' => now()]);

        return ['newly_requested' => $newlyRequested, 'newly_opened' => $newlyOpened];
    }
}
