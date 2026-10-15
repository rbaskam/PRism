<?php

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use App\Http\Resources\RepositoryResource;
use App\Jobs\PollNotifications;
use App\Jobs\SyncGithub;
use App\Models\GithubAccount;
use App\Models\Repository;
use App\Services\AppSettings;
use App\Services\Claude\ClaudeCode;
use App\Services\Github\GithubCli;
use App\Services\Github\GithubDeviceFlow;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The first-run flow: connect GitHub, choose repositories, then (optionally) connect Claude.
 */
class OnboardingController extends Controller
{
    public function github(GithubCli $githubCli, GithubDeviceFlow $deviceFlow): Response|RedirectResponse
    {
        return Inertia::render('onboarding/Github', [
            'account' => GithubAccount::current()?->only(['login', 'name', 'avatar_url']),
            'githubCliInstalled' => $githubCli->isInstalled(),
            'deviceFlowAvailable' => $deviceFlow->isAvailable(),
            'requiredScopes' => explode(' ', GithubDeviceFlow::SCOPES),
        ]);
    }

    public function repositories(): Response
    {
        return Inertia::render('onboarding/Repositories', [
            'tracked' => RepositoryResource::collection(Repository::query()->tracked()->orderBy('full_name')->get())->resolve(),
            'suggested' => GithubAccount::current()->untracked_review_requests ?? [],
        ]);
    }

    public function claude(ClaudeCode $claude, AppSettings $settings): Response
    {
        return Inertia::render('onboarding/Claude', [
            'status' => $claude->status(),
            'settings' => $settings->claudeConnectionSettings(),
        ]);
    }

    public function complete(AppSettings $settings): RedirectResponse
    {
        if (! $settings->hasCompletedOnboarding()) {
            $settings->set(AppSettings::ONBOARDING_COMPLETED_AT, now()->toIso8601String());
        }

        SyncGithub::dispatch();
        PollNotifications::dispatch();

        return redirect()->route('dashboard')->with('success', 'All set. Your pull requests are syncing now.');
    }
}
