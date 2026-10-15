<?php

namespace App\Jobs;

use App\Actions\PollGithubNotifications;
use App\Models\GithubAccount;
use App\Services\Github\GithubClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;

/**
 * Checks GitHub for new review requests and mentions.
 */
class PollNotifications implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 60;

    public function handle(PollGithubNotifications $poll): void
    {
        $account = GithubAccount::current();

        if ($account === null) {
            return;
        }

        try {
            $poll->handle($account);
        } catch (RequestException $exception) {
            if (! GithubClient::isUnauthorized($exception)) {
                throw $exception;
            }
        }
    }
}
