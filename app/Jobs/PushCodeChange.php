<?php

namespace App\Jobs;

use App\Enums\CodeChangeStatus;
use App\Enums\CodeChangeTarget;
use App\Models\CodeChange;
use App\Models\GithubAccount;
use App\Models\PullRequest;
use App\Services\AppSettings;
use App\Services\Git\Git;
use App\Services\Github\GithubClient;
use App\Services\Github\GraphqlQueries;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * Pushes approved changes to GitHub: onto the pull request's branch, or to a new branch with its own pull request.
 * Pushes exactly the commit the user reviewed, and checks again that pushing is allowed, since settings or the pull
 * request may have changed while the job waited.
 */
class PushCodeChange implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * The push plus a few GitHub requests. The worktree is removed afterwards by its own job.
     */
    public int $timeout = 600;

    public function __construct(public CodeChange $codeChange) {}

    public function handle(Git $git, GithubClient $github, AppSettings $settings): void
    {
        $change = $this->codeChange->fresh() ?? throw new \RuntimeException('These changes no longer exist.');

        if ($change->status !== CodeChangeStatus::Pushing) {
            return;
        }

        $refusal = $settings->allowsPushing() ? $change->pushRefusal() : 'Pushing is turned off in Settings → PR review.';

        if ($refusal !== null) {
            $change->update(['status' => CodeChangeStatus::Ready, 'error' => $refusal]);

            return;
        }

        $pullRequest = $change->pullRequest->load('repository');
        $account = GithubAccount::current() ?? throw new \RuntimeException('Connect GitHub before pushing.');
        $branch = $change->destinationBranch();
        $directory = $change->worktree_path !== null && is_dir($change->worktree_path) ? $change->worktree_path : $change->checkout_path;

        $git->push($directory, $change->destinationRepository(), (string) $change->head_sha, $branch, $account->token);

        // The commits are on GitHub now: nothing after this may report the push as failed.
        $change->update([
            'status' => CodeChangeStatus::Pushed,
            'pushed_at' => now(),
            'pushed_branch' => $branch,
            'error' => null,
        ]);

        if ($change->target === CodeChangeTarget::NewPullRequest) {
            try {
                $change->update(['created_pull_request_url' => $this->openPullRequest($github, $pullRequest, $change, $branch)]);
            } catch (Throwable $exception) {
                report($exception);
                $change->update(['error' => "Pushed to {$branch}, but GitHub didn't open the pull request: ".GithubClient::describeFailure($exception)]);
            }
        }

        RemoveCodeChangeWorktree::dispatch($change);
    }

    /**
     * Keep the changes so the user can try again (for example after pulling new commits) or discard them.
     */
    public function failed(?Throwable $exception): void
    {
        // The push went through and only something after it failed: that's recorded on the change already.
        if ($this->codeChange->fresh()?->status !== CodeChangeStatus::Pushing) {
            return;
        }

        $this->codeChange->update([
            'status' => CodeChangeStatus::Ready,
            'error' => 'Push failed: '.($exception ? GithubClient::describeFailure($exception) : 'unknown error'),
        ]);
    }

    /**
     * Open a pull request from the pushed branch into the pull request's branch and return its URL. When one is
     * already open (a push tried again after GitHub failed part way), that one is used instead of failing.
     */
    private function openPullRequest(GithubClient $github, PullRequest $pullRequest, CodeChange $change, string $branch): ?string
    {
        $repository = $pullRequest->repository->full_name;

        try {
            $created = $github->createPullRequest(
                $repository,
                "Fixes for #{$pullRequest->number}: {$pullRequest->title}",
                $branch,
                (string) $pullRequest->head_ref,
                trim(($change->summary ?? '')."\n\nChanges for #{$pullRequest->number}, made with Claude Code in PRism."),
            );

            return $created['html_url'] ?? null;
        } catch (RequestException $exception) {
            if (GithubClient::isUnprocessable($exception) && ($existing = $this->openPullRequestFrom($github, $repository, $branch)) !== null) {
                return $existing;
            }

            throw $exception;
        }
    }

    /**
     * The URL of an open pull request from a branch of the repository itself (not a fork's branch of the same name).
     */
    private function openPullRequestFrom(GithubClient $github, string $repository, string $branch): ?string
    {
        [$owner, $name] = explode('/', $repository, 2);
        $data = $github->graphql(GraphqlQueries::OPEN_PULL_REQUESTS_FROM_BRANCH, ['owner' => $owner, 'name' => $name, 'branch' => $branch]);

        $nodes = $data['repository']['pullRequests']['nodes'] ?? [];

        foreach (is_array($nodes) ? $nodes : [] as $node) {
            $headRepository = is_array($node) ? ($node['headRepository']['nameWithOwner'] ?? null) : null;

            if (is_string($headRepository) && strcasecmp($headRepository, $repository) === 0 && is_string($node['url'] ?? null)) {
                return $node['url'];
            }
        }

        return null;
    }
}
