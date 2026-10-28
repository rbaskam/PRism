<?php

namespace App\Http\Controllers;

use App\Http\Resources\PullRequestResource;
use App\Http\Resources\RepositoryResource;
use App\Models\GithubAccount;
use App\Models\Repository;
use App\Services\AppSettings;
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

    public function show(Repository $repository, AppSettings $settings): Response
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
            'reviewPrompt' => [
                'custom' => $repository->hasCustomReviewPrompt() ? $repository->review_prompt : null,
                'app_wide' => $settings->reviewPrompt(),
                'max_length' => AppSettings::REVIEW_PROMPT_MAX_LENGTH,
                'placeholders' => AppSettings::REVIEW_PROMPT_PLACEHOLDERS,
            ],
        ]);
    }

    /**
     * Update the local checkout path or the review prompt, or stop tracking the repository.
     *
     * A review prompt identical to the app-wide one is cleared, so the repository keeps following the app-wide prompt.
     */
    public function update(Request $request, Repository $repository, AppSettings $settings): RedirectResponse
    {
        $validated = $request->validate([
            'local_path' => ['sometimes', 'nullable', 'string', 'max:1024', function (string $attribute, mixed $value, Closure $fail): void {
                if (filled($value) && ! is_dir($value)) {
                    $fail('That folder does not exist on this computer.');
                }
            }],
            'is_tracked' => ['sometimes', 'boolean'],
            'review_prompt' => ['sometimes', 'nullable', 'string', 'max:'.AppSettings::REVIEW_PROMPT_MAX_LENGTH],
        ]);

        $updatesReviewPrompt = array_key_exists('review_prompt', $validated);

        if ($updatesReviewPrompt && trim((string) $validated['review_prompt']) === trim($settings->reviewPrompt())) {
            $validated['review_prompt'] = null;
        }

        $repository->update($validated);

        return back()->with('success', match (true) {
            ! $updatesReviewPrompt => "Updated {$repository->full_name}.",
            $repository->hasCustomReviewPrompt() => "Saved the review prompt for {$repository->full_name}.",
            default => "{$repository->full_name} now uses the app-wide review prompt.",
        });
    }
}
