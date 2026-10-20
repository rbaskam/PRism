<?php

namespace App\Actions;

use App\Models\GithubAccount;
use App\Models\Repository;
use App\Services\Github\GithubClient;

/**
 * Refreshes the teams the user belongs to (so team review requests are recognised)
 * and finds review requests in repositories the user is not tracking yet.
 */
class RefreshGithubAccount
{
    public function __construct(private readonly GithubClient $github) {}

    public function handle(GithubAccount $account): void
    {
        $tracked = Repository::query()->tracked()->pluck('full_name');

        $account->update([
            'teams' => $this->github->teams(),
            'teams_synced_at' => now(),
            'untracked_review_requests' => collect($this->github->repositoriesWithReviewRequests())
                ->reject(fn (string $repository): bool => $tracked->contains($repository))
                ->values()
                ->all(),
        ]);
    }
}
