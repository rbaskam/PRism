<?php

namespace App\Http\Controllers;

use App\Http\Resources\PullRequestResource;
use App\Models\GithubAccount;
use App\Models\PullRequest;
use App\Models\Repository;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The home page: every open pull request across tracked repositories that involves the user.
 */
class DashboardController extends Controller
{
    public function index(): Response
    {
        $account = GithubAccount::current();

        $pullRequests = PullRequest::query()
            ->openOrRecentlyClosed()
            ->involvingMe()
            ->whereRelation('repository', 'is_tracked', true)
            ->with(['repository', 'latestReviewRun'])
            ->withFindingCounts()
            ->orderByDesc('github_updated_at')
            ->orderByDesc('id')
            ->get();

        return Inertia::render('Dashboard', [
            'pullRequests' => PullRequestResource::collection($pullRequests)->resolve(),
            'repositories' => Repository::query()->tracked()->orderBy('full_name')->get(['id', 'full_name']),
            'teams' => collect($account->teams ?? [])->map(fn (array $team): string => "{$team['organization']}/{$team['slug']}")->values(),
            'untrackedReviewRequests' => $account->untracked_review_requests ?? [],
            'lastSyncedAt' => Repository::query()->tracked()->latest('synced_at')->first()?->synced_at?->toIso8601String(),
        ]);
    }
}
