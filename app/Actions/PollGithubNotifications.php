<?php

namespace App\Actions;

use App\Enums\AlertType;
use App\Jobs\SyncRepository;
use App\Models\GithubAccount;
use App\Models\GithubNotification;
use App\Models\PullRequest;
use App\Models\Repository;
use App\Services\AppSettings;
use App\Services\DesktopNotifier;
use App\Services\Github\GithubClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Pulls the user's GitHub notifications, raises the desktop alerts the user switched on, and
 * refreshes repositories where a review was newly requested.
 */
class PollGithubNotifications
{
    public function __construct(
        private readonly GithubClient $github,
        private readonly AppSettings $settings,
        private readonly DesktopNotifier $notifier,
    ) {}

    public function handle(GithubAccount $account): void
    {
        $isFirstPoll = $account->notifications_polled_at === null;
        $result = $this->github->notifications($isFirstPoll ? null : $account->notifications_last_modified);

        $account->update([
            'notifications_polled_at' => now(),
            'notifications_last_modified' => $result['last_modified'],
        ]);

        $repositoriesToSync = collect();

        foreach ($result['notifications'] as $thread) {
            $notification = $this->store($thread);
            $alert = AlertType::fromGithubReason($notification->reason);

            if (! $notification->is_unread || $alert === null) {
                continue;
            }

            if ($notification->desktop_notified_at !== null && $notification->desktop_notified_at->gte($notification->github_updated_at)) {
                continue;
            }

            $isNew = $notification->desktop_notified_at === null;

            if ($isNew && $notification->reason === 'review_requested') {
                $repositoriesToSync->push($notification->repository_full_name);
            }

            if (! $isFirstPoll && $this->wantsAlert($alert, $isNew)) {
                $this->notifier->send(
                    $alert->title($notification->reason),
                    "{$notification->repository_full_name}: {$notification->subject_title}",
                    DesktopNotifier::GITHUB_NOTIFICATION_REFERENCE.$notification->id,
                );
            }

            $notification->update(['desktop_notified_at' => now()]);
        }

        Repository::query()
            ->tracked()
            ->whereIn('full_name', $repositoriesToSync->unique())
            ->each(fn (Repository $repository) => SyncRepository::dispatch($repository));
    }

    /**
     * @param  array<string, mixed>  $thread
     */
    private function store(array $thread): GithubNotification
    {
        $repositoryName = $thread['repository']['full_name'];
        $subjectUrl = $thread['subject']['url'] ?? null;

        $notification = GithubNotification::query()->updateOrCreate(['github_id' => (string) $thread['id']], [
            'reason' => $thread['reason'],
            'subject_type' => $thread['subject']['type'],
            'subject_title' => Str::limit($thread['subject']['title'], 250),
            'subject_url' => $subjectUrl,
            'html_url' => $this->htmlUrl($subjectUrl, $thread['repository']['html_url'] ?? null),
            'repository_full_name' => $repositoryName,
            'is_unread' => (bool) $thread['unread'],
            'github_updated_at' => Carbon::parse($thread['updated_at']),
        ]);

        if ($notification->pull_request_id === null && ($number = $notification->pullRequestNumber()) !== null) {
            $notification->update(['pull_request_id' => PullRequest::query()
                ->whereRelation('repository', 'full_name', $repositoryName)
                ->where('number', $number)
                ->value('id')]);
        }

        return $notification;
    }

    /**
     * Review requests and mentions alert when they first arrive; later activity on the same pull request only
     * alerts when the user asked for it. Other alert types report every update.
     */
    private function wantsAlert(AlertType $alert, bool $isNew): bool
    {
        if (! $this->settings->sendsAlert($alert)) {
            return false;
        }

        return $isNew || $alert->alertsOnEveryUpdate() || $this->settings->alertsOnFollowUpActivity();
    }

    /**
     * Turn an API URL such as https://api.github.com/repos/acme/app/pulls/12 into its github.com page.
     */
    private function htmlUrl(?string $subjectUrl, ?string $repositoryUrl): ?string
    {
        if ($subjectUrl === null || ! str_starts_with($subjectUrl, GithubClient::BASE_URL.'/repos/')) {
            return $repositoryUrl;
        }

        return Str::of($subjectUrl)
            ->replace(GithubClient::BASE_URL.'/repos/', 'https://github.com/')
            ->replaceMatches('#/pulls/(\d+)$#', '/pull/$1')
            ->toString();
    }
}
