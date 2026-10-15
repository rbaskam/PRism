<?php

namespace App\Http\Middleware;

use App\Actions\QueuePullRequestReview;
use App\Enums\AiTaskStatus;
use App\Models\Alert;
use App\Models\GithubAccount;
use App\Models\GithubNotification;
use App\Models\PullRequest;
use App\Models\ReviewRun;
use App\Services\AppSettings;
use App\Services\Changelog;
use App\Services\UpdateChecker;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Inertia\Support\Header;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        if ($this->isBackgroundReloadWithoutFlash($request)) {
            // A poll or deferred load that doesn't render flash messages must not use them up before the page that will.
            $request->session()->reflash();
        }

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'isNative' => (bool) config('nativephp-internal.running'),
            'isContainer' => (bool) config('prism.running_in_container'),
            'github' => fn (): ?array => GithubAccount::current()?->only(['login', 'name', 'avatar_url']),
            'claude' => fn (): array => ['configured' => app(AppSettings::class)->isClaudeConfigured()],
            'appLock' => fn (): array => ['enabled' => app(AppSettings::class)->isAppLockEnabled()],
            'appVersion' => fn (): string => app(UpdateChecker::class)->currentVersion(),
            'update' => fn (): ?array => app(UpdateChecker::class)->updateIsAvailable() ? app(UpdateChecker::class)->latestRelease() : null,
            'whatsNew' => fn (): ?array => $this->whatsNew(),
            'counts' => fn (): array => $this->counts(),
            'flash' => fn (): array => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
        ];
    }

    /**
     * Sidebar badges, plus the pull requests Claude is reviewing so the sidebar can link to them.
     *
     * @return array{awaiting_review: int, unread_inbox: int, unread_alerts: int, active_reviews: int, reviewing: list<array{pull_request_id: int, reference: string, title: string, status: string}>}
     */
    private function counts(): array
    {
        $reviewing = ReviewRun::query()
            ->whereIn('status', [AiTaskStatus::Queued, AiTaskStatus::Running])
            ->where('created_at', '>=', now()->subMinutes(QueuePullRequestReview::STALE_AFTER_MINUTES))
            ->with('pullRequest.repository')
            ->oldest('id')
            ->get()
            ->map(fn (ReviewRun $run): array => [
                'pull_request_id' => $run->pull_request_id,
                'reference' => $run->pullRequest->reference(),
                'title' => $run->pullRequest->title,
                'status' => $run->status->value,
            ])
            ->values()
            ->all();

        return [
            'awaiting_review' => PullRequest::query()->awaitingMyReview()->count(),
            'unread_inbox' => GithubNotification::query()->where('is_unread', true)->count(),
            'unread_alerts' => Alert::query()->unread()->count(),
            'active_reviews' => count($reviewing),
            'reviewing' => $reviewing,
        ];
    }

    /**
     * The changelog for the version the user just updated to, until they dismiss it.
     *
     * @return array{version: string, notes: string}|null
     */
    private function whatsNew(): ?array
    {
        $version = app(UpdateChecker::class)->unseenVersion();
        $notes = $version === null ? null : app(Changelog::class)->notesFor($version);

        return filled($notes) ? ['version' => $version, 'notes' => $notes] : null;
    }

    private function isBackgroundReloadWithoutFlash(Request $request): bool
    {
        $only = array_filter(explode(',', (string) $request->header(Header::PARTIAL_ONLY)));
        $except = array_filter(explode(',', (string) $request->header(Header::PARTIAL_EXCEPT)));

        return ($only !== [] && ! in_array('flash', $only, true)) || in_array('flash', $except, true);
    }
}
