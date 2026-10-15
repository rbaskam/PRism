<?php

namespace App\Actions;

use App\Models\GithubAccount;
use App\Models\GithubNotification;
use App\Models\PullRequest;
use App\Models\Repository;
use App\Services\Github\GithubClient;
use App\Services\Github\GithubException;
use App\Services\Github\PullRequestMapper;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

/**
 * Finds the pull request a GitHub notification is about so it can open in the app, fetching it from
 * GitHub when PRism hasn't synced it yet (a brand new review request, or a repository the user
 * doesn't track). Returns null for notifications that aren't about a pull request.
 */
class FindNotificationPullRequest
{
    public function __construct(
        private readonly GithubClient $github,
        private readonly PullRequestMapper $mapper,
    ) {}

    public function handle(GithubNotification $notification): ?PullRequest
    {
        if ($notification->pull_request_id !== null && ($pullRequest = PullRequest::find($notification->pull_request_id))) {
            return $pullRequest;
        }

        $number = $notification->pullRequestNumber();
        $account = GithubAccount::current();

        if ($number === null || $account === null) {
            return null;
        }

        $pullRequest = PullRequest::query()
            ->whereRelation('repository', 'full_name', $notification->repository_full_name)
            ->where('number', $number)
            ->first();

        if ($pullRequest === null) {
            try {
                $pullRequest = $this->import($notification->repository_full_name, $number, $account);
            } catch (RequestException|ConnectionException|GithubException $exception) {
                report($exception);

                return null;
            }
        }

        $notification->update(['pull_request_id' => $pullRequest->id]);

        return $pullRequest;
    }

    private function import(string $repositoryFullName, int $number, GithubAccount $account): PullRequest
    {
        $repository = Repository::query()->where('full_name', $repositoryFullName)->first();

        if ($repository === null) {
            $details = $this->github->repository($repositoryFullName);
            $repository = Repository::query()->updateOrCreate(['github_id' => $details['github_id']], [...$details, 'is_tracked' => false]);
        }

        $node = $this->github->pullRequest($repository->owner, $repository->name, $number);

        return $repository->pullRequests()->updateOrCreate(['number' => $number], $this->mapper->attributes($node, $account));
    }
}
