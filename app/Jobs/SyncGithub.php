<?php

namespace App\Jobs;

use App\Actions\RefreshGithubAccount;
use App\Models\GithubAccount;
use App\Models\Repository;
use App\Services\Github\GithubClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;

/**
 * Refreshes the user's teams, then queues a sync for every tracked repository.
 */
class SyncGithub implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 120;

    public function handle(RefreshGithubAccount $refreshAccount): void
    {
        $account = GithubAccount::current();

        if ($account === null) {
            return;
        }

        try {
            $refreshAccount->handle($account);
        } catch (RequestException $exception) {
            if (GithubClient::isUnauthorized($exception)) {
                return;
            }

            report($exception);
        }

        Repository::query()->tracked()->each(fn (Repository $repository) => SyncRepository::dispatch($repository));
    }
}
