<?php

namespace App\Listeners;

use App\Actions\FindNotificationPullRequest;
use App\Models\GithubNotification;
use App\Providers\NativeAppServiceProvider;
use App\Services\DesktopNotifier;
use Native\Desktop\Events\Notifications\NotificationClicked;
use Native\Desktop\Facades\Shell;
use Native\Desktop\Facades\Window;

/**
 * Opens the pull request behind a desktop notification in the app when the user clicks it (fetching it
 * from GitHub first if it hasn't synced yet). Other notifications open on github.com.
 *
 * This listener must stay synchronous: it runs inside the PHP server request Electron makes,
 * so route() points at the desktop app rather than APP_URL.
 */
class OpenNotificationTarget
{
    public function __construct(private readonly FindNotificationPullRequest $findPullRequest) {}

    public function handle(NotificationClicked $event): void
    {
        if (str_starts_with($event->reference, DesktopNotifier::PULL_REQUEST_REFERENCE)) {
            $this->openPullRequest((int) str($event->reference)->after(DesktopNotifier::PULL_REQUEST_REFERENCE)->toString());

            return;
        }

        if (! str_starts_with($event->reference, DesktopNotifier::GITHUB_NOTIFICATION_REFERENCE)) {
            return;
        }

        $notification = GithubNotification::find((int) str($event->reference)->after(DesktopNotifier::GITHUB_NOTIFICATION_REFERENCE)->toString());

        if ($notification === null) {
            return;
        }

        $pullRequest = $this->findPullRequest->handle($notification);

        if ($pullRequest !== null) {
            $this->openPullRequest($pullRequest->id);
        } elseif ($notification->html_url !== null) {
            Shell::openExternal($notification->html_url);
        }
    }

    private function openPullRequest(int $pullRequestId): void
    {
        Window::open(NativeAppServiceProvider::MAIN_WINDOW);
        Window::get(NativeAppServiceProvider::MAIN_WINDOW)->route('pull-requests.show', ['pullRequest' => $pullRequestId]);
    }
}
