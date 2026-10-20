<?php

namespace App\Actions;

use App\Models\PullRequest;
use App\Models\PullRequestFileView;
use App\Services\Github\GithubClient;
use App\Services\Github\GithubException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

/**
 * Loads a pull request's changed files from GitHub and works out which ones the user has viewed,
 * combining the app's own records with the viewed state GitHub keeps for the web UI. The files are
 * saved per head commit, so opening the tab again is instant until new commits are pushed.
 */
class LoadPullRequestFiles
{
    public function __construct(private readonly GithubClient $github) {}

    /**
     * @return list<array{path: string, previous_path: ?string, status: string, additions: int, deletions: int, sha: ?string, patch: ?string, is_viewed: bool, changed_since_viewed: bool}>
     */
    public function handle(PullRequest $pullRequest): array
    {
        $snapshot = $pullRequest->files_head_sha !== null && $pullRequest->files_head_sha === $pullRequest->head_sha
            ? $pullRequest->files_snapshot
            : null;
        $isNewDiff = $snapshot === null;

        // A pull request's diff only changes with new commits, so it is fetched once per head commit.
        if ($snapshot === null) {
            $repository = $pullRequest->repository;
            $snapshot = ['files' => $this->github->pullRequestFiles($repository->full_name, $pullRequest->number), 'github_viewed_states' => []];
        }

        // Which files are ticked Viewed on github.com can change at any time, so that is read every time; the last
        // known states are only used when GitHub can't be reached.
        $latestGithubStates = $this->githubViewedStates($pullRequest);

        if ($isNewDiff || ($latestGithubStates !== null && $latestGithubStates !== $snapshot['github_viewed_states'])) {
            $snapshot['github_viewed_states'] = $latestGithubStates ?? $snapshot['github_viewed_states'];
            $pullRequest->update(['files_snapshot' => $snapshot, 'files_head_sha' => $pullRequest->head_sha]);
        }

        $views = $pullRequest->fileViews()->get()->keyBy('path');
        $githubStates = $snapshot['github_viewed_states'];

        return array_map(function (array $file) use ($views, $githubStates): array {
            /** @var PullRequestFileView|null $view */
            $view = $views->get($file['path']);
            $githubState = $githubStates[$file['path']] ?? null;
            $viewedThisVersion = $view !== null && ($view->file_sha === null || $view->file_sha === $file['sha']);

            return [
                ...$file,
                'is_viewed' => $viewedThisVersion || $githubState === 'VIEWED',
                'changed_since_viewed' => ! $viewedThisVersion && $githubState !== 'VIEWED' && ($view !== null || $githubState === 'DISMISSED'),
            ];
        }, $snapshot['files']);
    }

    /**
     * Remember the user's viewed state on GitHub without fetching the files again.
     */
    public function rememberGithubViewedState(PullRequest $pullRequest, string $path, bool $viewed): void
    {
        $snapshot = $pullRequest->files_snapshot;

        if ($snapshot === null) {
            return;
        }

        $snapshot['github_viewed_states'][$path] = $viewed ? 'VIEWED' : 'UNVIEWED';
        $pullRequest->update(['files_snapshot' => $snapshot]);
    }

    /**
     * The user's viewed state on GitHub for each file, or null when GitHub can't be reached.
     *
     * @return array<string, string>|null
     */
    private function githubViewedStates(PullRequest $pullRequest): ?array
    {
        $repository = $pullRequest->repository;

        try {
            return $this->github->fileViewedStates($repository->owner, $repository->name, $pullRequest->number);
        } catch (RequestException|ConnectionException|GithubException) {
            return null;
        }
    }
}
