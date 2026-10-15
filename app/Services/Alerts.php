<?php

namespace App\Services;

use App\Enums\AlertType;
use App\Models\Alert;
use App\Models\PullRequest;

/**
 * Raises alerts. Each one is kept for the bell in the header and, when desktop notifications are on, shown on the
 * desktop as well. Kinds of alert the user switched off in Settings → Notifications aren't raised at all.
 */
class Alerts
{
    public function __construct(
        private readonly AppSettings $settings,
        private readonly DesktopNotifier $notifier,
    ) {}

    /**
     * @param  string|null  $url  Where clicking the alert goes: a path in the app, or a github.com link.
     * @param  string|null  $reference  Echoed back when the desktop notification is clicked (see DesktopNotifier).
     */
    public function raise(AlertType $type, string $title, string $body, ?string $url = null, ?PullRequest $pullRequest = null, ?string $reference = null): ?Alert
    {
        if (! $this->settings->wantsAlert($type)) {
            return null;
        }

        $alert = Alert::query()->create([
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'pull_request_id' => $pullRequest?->id,
        ]);

        if ($this->settings->sendsDesktopNotifications()) {
            $this->notifier->send($title, $body, $reference ?? ($pullRequest ? DesktopNotifier::PULL_REQUEST_REFERENCE.$pullRequest->id : null));
        }

        return $alert;
    }

    /**
     * The path to a pull request in the app, opened on one of its tabs: findings, files, questions, changes,
     * conversation, description or history.
     */
    public static function pullRequestUrl(PullRequest $pullRequest, ?string $tab = null): string
    {
        return route('pull-requests.show', array_filter(['pullRequest' => $pullRequest->id, 'tab' => $tab]), absolute: false);
    }
}
