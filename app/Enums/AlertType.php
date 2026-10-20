<?php

namespace App\Enums;

/**
 * The kinds of desktop alert PRism can send, each with its own switch in Settings → Notifications.
 * The value is the setting key under "notifications.".
 */
enum AlertType: string
{
    case ReviewRequests = 'review_requests';
    case Mentions = 'mentions';
    case MyPullRequests = 'my_pull_requests';
    case Commented = 'commented';
    case Ci = 'ci';
    case NewPullRequests = 'new_pull_requests';
    case ReviewFinished = 'review_finished';

    /**
     * Which alert a GitHub notification belongs to, from its reason.
     *
     * @see https://docs.github.com/en/rest/activity/notifications#about-notification-reasons
     */
    public static function fromGithubReason(string $reason): ?self
    {
        return match ($reason) {
            'review_requested', 'assign' => self::ReviewRequests,
            'mention', 'team_mention' => self::Mentions,
            'author' => self::MyPullRequests,
            'comment' => self::Commented,
            'ci_activity' => self::Ci,
            default => null,
        };
    }

    public function isOnByDefault(): bool
    {
        return in_array($this, [self::ReviewRequests, self::Mentions, self::MyPullRequests, self::ReviewFinished], true);
    }

    /**
     * Review requests and mentions alert once per pull request; GitHub bumps those threads on every
     * later comment or push, which only alerts again when the user asks for activity updates. The
     * others exist to report each new comment or result, so every update alerts.
     */
    public function alertsOnEveryUpdate(): bool
    {
        return in_array($this, [self::MyPullRequests, self::Commented, self::Ci], true);
    }

    public function title(string $reason): string
    {
        return match ($reason) {
            'assign' => 'Assigned to you',
            'team_mention' => 'Your team was mentioned',
            default => match ($this) {
                self::ReviewRequests => 'Review requested',
                self::Mentions => 'You were mentioned',
                self::MyPullRequests => 'New activity on your pull request',
                self::Commented => 'New reply on a pull request you commented on',
                self::Ci => 'CI finished',
                self::NewPullRequests => 'New pull request',
                self::ReviewFinished => 'AI review finished',
            },
        };
    }
}
