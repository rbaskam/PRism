<?php

namespace App\Http\Controllers;

use App\Http\Resources\PullRequestResource;
use App\Http\Resources\RepositoryResource;
use App\Models\GithubAccount;
use App\Models\Repository;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RepositoryController extends Controller
{
    public function index(): Response
    {
        $repositories = Repository::query()
            ->tracked()
            ->withPullRequestCounts()
            ->orderBy('full_name')
            ->get();

        return Inertia::render('repositories/Index', [
            'repositories' => RepositoryResource::collection($repositories)->resolve(),
        ]);
    }

    public function show(Repository $repository): Response
    {
        $pullRequests = $repository->pullRequests()
            ->openOrRecentlyClosed()
            ->with(['repository', 'latestReviewRun'])
            ->withFindingCounts()
            ->orderByDesc('github_updated_at')
            ->orderByDesc('id')
            ->get();

        return Inertia::render('repositories/Show', [
            'repository' => RepositoryResource::make($repository)->resolve(),
            'pullRequests' => PullRequestResource::collection($pullRequests)->resolve(),
            'teams' => collect(GithubAccount::current()->teams ?? [])->map(fn (array $team): string => "{$team['organization']}/{$team['slug']}")->values(),
        ]);
    }

    /**
     * Update the local checkout path or stop tracking the repository.
     */
    public function update(Request $request, Repository $repository): RedirectResponse
    {
        $validated = $request->validate([
            'local_path' => ['sometimes', 'nullable', 'string', 'max:1024', function (string $attribute, mixed $value, Closure $fail): void {
                if (filled($value) && ! is_dir($value)) {
                    $fail('That folder does not exist on this computer.');
                }
            }],
            'is_tracked' => ['sometimes', 'boolean'],
        ]);

        $repository->update($validated);

        return back()->with('success', "Updated {$repository->full_name}.");
    }
}
