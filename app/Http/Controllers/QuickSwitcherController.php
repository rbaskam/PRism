<?php

namespace App\Http\Controllers;

use App\Models\PullRequest;
use App\Models\Repository;
use Illuminate\Http\JsonResponse;

/**
 * What the ⌘K quick switcher can jump to: the tracked repositories, and their open and recently closed pull requests.
 * It answers with JSON when the switcher opens, straight from the database, so it never waits on GitHub.
 */
class QuickSwitcherController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $repositories = Repository::query()
            ->tracked()
            ->withPullRequestCounts()
            ->orderBy('full_name')
            ->get();

        $repositoryNames = $repositories->pluck('full_name', 'id');

        $pullRequests = PullRequest::query()
            ->openOrRecentlyClosed()
            ->whereIn('repository_id', $repositories->modelKeys())
            ->orderByRaw('CASE WHEN state = ? THEN 0 ELSE 1 END', [PullRequest::STATE_OPEN])
            ->orderByDesc('github_updated_at')
            ->orderByDesc('id')
            ->get([
                'id',
                'repository_id',
                'number',
                'title',
                'state',
                'is_draft',
                'author_login',
                'head_ref',
                'is_authored_by_me',
                'is_review_requested_from_me',
                'my_review_state',
            ]);

        return response()->json([
            'repositories' => $repositories->map(fn (Repository $repository): array => [
                'id' => $repository->id,
                'full_name' => $repository->full_name,
                'counts' => $repository->pullRequestCounts(),
            ])->all(),
            'pull_requests' => $pullRequests->map(fn (PullRequest $pullRequest): array => [
                'id' => $pullRequest->id,
                'repository' => $repositoryNames[$pullRequest->repository_id],
                'number' => $pullRequest->number,
                'title' => $pullRequest->title,
                'state' => $pullRequest->state,
                'is_draft' => $pullRequest->is_draft,
                'author_login' => $pullRequest->author_login,
                'head_ref' => $pullRequest->head_ref,
                'attention' => $pullRequest->attention()->value,
            ])->all(),
        ]);
    }
}
