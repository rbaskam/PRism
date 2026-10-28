<?php

namespace App\Actions;

use App\Models\GithubAccount;
use App\Models\PullRequest;
use App\Models\Repository;
use App\Services\Github\GithubClient;
use App\Services\Github\GithubException;
use App\Services\Github\PullRequestMapper;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Pulls a repository's open pull requests from GitHub and closes any that are no longer open, noting which were merged.
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

        $this->closeThoseNoLongerOpen($repository->pullRequests()->open()->whereNotIn('id', $seenIds)->get());

        $repository->update(['synced_at' => now()]);

        return ['newly_requested' => $newlyRequested, 'newly_opened' => $newlyOpened];
    }

    /**
     * Close pull requests that left the open list, asking GitHub whether each one was merged or closed. One that
     * GitHub still reports as open (it moved while the list was being paged through) is left for the next sync. When
     * GitHub can't be asked, they are closed now and show as closed rather than merged.
     *
     * @param  Collection<int, PullRequest>  $pullRequests
     */
    private function closeThoseNoLongerOpen(Collection $pullRequests): void
    {
        if ($pullRequests->isEmpty()) {
            return;
        }

        try {
            $states = $this->github->pullRequestStates($pullRequests->pluck('node_id')->all());
        } catch (RequestException|ConnectionException|GithubException $exception) {
            report($exception);
            $states = [];
        }

        foreach ($pullRequests as $pullRequest) {
            $state = $states[$pullRequest->node_id] ?? null;

            if ($state !== null && $state['state'] === 'OPEN') {
                continue;
            }

            $pullRequest->update([
                'state' => PullRequest::STATE_CLOSED,
                'closed_at' => isset($state['closed_at']) ? Carbon::parse($state['closed_at']) : now(),
                'merged_at' => isset($state['merged_at']) ? Carbon::parse($state['merged_at']) : null,
                'is_review_requested_from_me' => false,
            ]);
        }
    }
}
