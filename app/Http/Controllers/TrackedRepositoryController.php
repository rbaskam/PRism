<?php

namespace App\Http\Controllers;

use App\Jobs\SyncRepository;
use App\Models\GithubAccount;
use App\Models\Repository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Saves which repositories the user wants to follow.
 */
class TrackedRepositoryController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'repositories' => ['present', 'array', 'max:500'],
            'repositories.*.github_id' => ['required', 'integer'],
            'repositories.*.owner' => ['required', 'string', 'max:255'],
            'repositories.*.name' => ['required', 'string', 'max:255'],
            'repositories.*.full_name' => ['required', 'string', 'max:255'],
            'repositories.*.html_url' => ['required', 'url:https'],
            'repositories.*.default_branch' => ['nullable', 'string', 'max:255'],
            'repositories.*.is_private' => ['boolean'],
            'redirect_to' => ['nullable', 'in:onboarding.claude,settings.github'],
        ]);

        /** @var list<array{github_id: int, owner: string, name: string, full_name: string, html_url: string, default_branch?: ?string, is_private?: bool}> $repositories */
        $repositories = $validated['repositories'];
        $selected = collect($repositories);

        Repository::query()
            ->whereNotIn('github_id', $selected->pluck('github_id'))
            ->update(['is_tracked' => false]);

        foreach ($selected as $repository) {
            $tracked = Repository::query()->updateOrCreate(['github_id' => $repository['github_id']], [
                'owner' => $repository['owner'],
                'name' => $repository['name'],
                'full_name' => $repository['full_name'],
                'html_url' => $repository['html_url'],
                'default_branch' => $repository['default_branch'] ?? null,
                'is_private' => $repository['is_private'] ?? false,
                'is_tracked' => true,
            ]);

            if ($tracked->wasRecentlyCreated || $tracked->wasChanged('is_tracked')) {
                SyncRepository::dispatch($tracked);
            }
        }

        $account = GithubAccount::current();
        $account->update([
            'untracked_review_requests' => collect($account->untracked_review_requests ?? [])
                ->diff($selected->pluck('full_name'))
                ->values()
                ->all(),
        ]);

        return redirect()
            ->route($validated['redirect_to'] ?? 'settings.github')
            ->with('success', trans_choice('{1} Tracking 1 repository.|[0,*] Tracking :count repositories.', $selected->count()));
    }
}
