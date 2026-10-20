<?php

namespace App\Http\Controllers;

use App\Actions\FindNotificationPullRequest;
use App\Http\Resources\GithubNotificationResource;
use App\Models\GithubNotification;
use App\Services\Github\GithubClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Native\Desktop\Facades\Shell;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * GitHub notifications: review requests, mentions and activity on the user's pull requests.
 */
class InboxController extends Controller
{
    public function index(): Response
    {
        $notifications = GithubNotification::query()
            ->orderByDesc('github_updated_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return Inertia::render('inbox/Index', [
            'notifications' => GithubNotificationResource::collection($notifications)->resolve(),
        ]);
    }

    /**
     * Open a notification: its pull request in the app (fetched from GitHub if needed), anything else on github.com.
     */
    public function open(GithubNotification $notification, FindNotificationPullRequest $findPullRequest): SymfonyResponse
    {
        $pullRequest = $findPullRequest->handle($notification);

        if ($pullRequest !== null) {
            return redirect()->route('pull-requests.show', $pullRequest);
        }

        if ($notification->html_url === null) {
            return back()->with('error', "This notification doesn't link anywhere.");
        }

        if (config('nativephp-internal.running')) {
            Shell::openExternal($notification->html_url);

            return back();
        }

        return Inertia::location($notification->html_url);
    }

    public function markAsRead(GithubNotification $notification, GithubClient $github): RedirectResponse
    {
        try {
            $github->markNotificationAsRead($notification->github_id);
        } catch (RequestException|ConnectionException $exception) {
            return back()->with('error', GithubClient::describeFailure($exception));
        }

        $notification->update(['is_unread' => false]);

        return back();
    }

    public function markAllAsRead(GithubClient $github): RedirectResponse
    {
        try {
            $github->markAllNotificationsAsRead();
        } catch (RequestException|ConnectionException $exception) {
            return back()->with('error', GithubClient::describeFailure($exception));
        }

        GithubNotification::query()->where('is_unread', true)->update(['is_unread' => false]);

        return back()->with('success', 'All notifications marked as read.');
    }
}
