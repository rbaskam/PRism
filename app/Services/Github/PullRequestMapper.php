<?php

namespace App\Services\Github;

use App\Enums\ReviewState;
use App\Models\GithubAccount;
use App\Models\PullRequest;
use Illuminate\Support\Carbon;

/**
 * Turns a GitHub GraphQL pull request node into attributes for the PullRequest model,
 * working out how the connected user is involved along the way.
 */
class PullRequestMapper
{
    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    public function attributes(array $node, GithubAccount $account): array
    {
        $myLogin = $account->login;
        $isAuthoredByMe = strcasecmp($node['author']['login'] ?? '', $myLogin) === 0;
        $requestedReviewers = $this->withoutRequestsAnsweredByMe($this->requestedReviewers($node), $node, $account);
        // GitHub doesn't let you review your own pull request, so a request sent to one of your teams (e.g. by
        // CODEOWNERS) isn't one for you.
        $requestsForMe = $isAuthoredByMe ? [] : $requestedReviewers;
        $myTeams = collect($requestsForMe)
            ->where('type', 'team')
            ->filter(fn (array $reviewer): bool => $this->isMe($reviewer, $account))
            ->map(fn (array $reviewer): string => "{$reviewer['organization']}/{$reviewer['slug']}")
            ->values()
            ->all();
        $isRequestedDirectly = collect($requestsForMe)
            ->contains(fn (array $reviewer): bool => $reviewer['type'] === 'user' && $this->isMe($reviewer, $account));
        $myReview = $this->myLatestReview($node, $myLogin);
        $isOpen = $node['state'] === 'OPEN';

        $attributes = [
            'node_id' => $node['id'],
            'number' => $node['number'],
            'title' => $node['title'],
            'html_url' => $node['url'],
            'state' => $isOpen ? PullRequest::STATE_OPEN : PullRequest::STATE_CLOSED,
            'is_draft' => (bool) $node['isDraft'],
            'author_login' => $node['author']['login'] ?? null,
            'author_avatar_url' => $node['author']['avatarUrl'] ?? null,
            'head_ref' => $node['headRefName'],
            'base_ref' => $node['baseRefName'],
            'head_sha' => $node['headRefOid'],
            'additions' => $node['additions'],
            'deletions' => $node['deletions'],
            'changed_files' => $node['changedFiles'],
            'comments_count' => $node['comments']['totalCount'] ?? 0,
            'review_decision' => $node['reviewDecision'],
            'checks_state' => $node['commits']['nodes'][0]['commit']['statusCheckRollup']['state'] ?? null,
            'mergeable' => $node['mergeable'],
            'my_review_state' => $myReview['state'] ?? null,
            'my_review_submitted_at' => isset($myReview['submittedAt']) ? Carbon::parse($myReview['submittedAt']) : null,
            'my_review_commit_sha' => $myReview['commit']['oid'] ?? null,
            // GitHub keeps review requests on closed pull requests, but they no longer need anyone.
            'is_review_requested_from_me' => $isOpen && ($isRequestedDirectly || $myTeams !== []),
            'is_review_requested_directly' => $isOpen && $isRequestedDirectly,
            'requested_via_teams' => $myTeams,
            'requested_reviewers' => $requestedReviewers,
            'labels' => collect($this->nodes($node, 'labels'))->map(fn (array $label): array => [
                'name' => $label['name'],
                'color' => $label['color'],
            ])->all(),
            'is_authored_by_me' => $isAuthoredByMe,
            'is_assigned_to_me' => collect($this->nodes($node, 'assignees'))
                ->contains(fn (array $assignee): bool => strcasecmp($assignee['login'], $myLogin) === 0),
            'github_created_at' => Carbon::parse($node['createdAt']),
            'github_updated_at' => Carbon::parse($node['updatedAt']),
            'closed_at' => isset($node['closedAt']) ? Carbon::parse($node['closedAt']) : null,
            'synced_at' => now(),
        ];

        if (array_key_exists('bodyHTML', $node)) {
            $attributes['body_html'] = $node['bodyHTML'];
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<array{type: string, login?: string, organization?: string, slug?: string, name?: string}>
     */
    private function requestedReviewers(array $node): array
    {
        return collect($this->nodes($node, 'reviewRequests'))
            ->pluck('requestedReviewer')
            ->filter()
            ->map($this->reviewer(...))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $reviewer
     * @return array{type: string, login?: string, organization?: string, slug?: string, name?: string}|null
     */
    private function reviewer(array $reviewer): ?array
    {
        return match ($reviewer['__typename']) {
            'User' => ['type' => 'user', 'login' => $reviewer['login']],
            'Team' => [
                'type' => 'team',
                'organization' => $reviewer['organization']['login'],
                'slug' => $reviewer['slug'],
                'name' => $reviewer['name'],
            ],
            default => null,
        };
    }

    /**
     * Leave out requests for the user, or one of their teams, made before the user's latest review. GitHub drops a
     * request once it is answered, but keeps listing it for a few seconds afterwards, which would put a pull request
     * the user has just approved back in their queue. A request made after the review is a re-request and stays.
     *
     * @param  list<array{type: string, login?: string, organization?: string, slug?: string, name?: string}>  $requestedReviewers
     * @param  array<string, mixed>  $node
     * @return list<array{type: string, login?: string, organization?: string, slug?: string, name?: string}>
     */
    private function withoutRequestsAnsweredByMe(array $requestedReviewers, array $node, GithubAccount $account): array
    {
        $myLastReviewedAt = collect([...$this->nodes($node, 'latestOpinionatedReviews'), ...$this->nodes($node, 'latestReviews')])
            ->filter(fn (array $review): bool => strcasecmp($review['author']['login'] ?? '', $account->login) === 0
                && ReviewState::tryFrom($review['state']) !== ReviewState::Pending
                && isset($review['submittedAt']))
            ->map(fn (array $review): Carbon => Carbon::parse($review['submittedAt']))
            ->sort()
            ->last();

        if ($myLastReviewedAt === null) {
            return $requestedReviewers;
        }

        $lastRequestedAt = collect($this->nodes($node, 'reviewRequestedEvents'))
            ->filter(fn (array $event): bool => isset($event['createdAt'], $event['requestedReviewer']))
            ->map(fn (array $event): array => ['reviewer' => $this->reviewer($event['requestedReviewer']), 'at' => Carbon::parse($event['createdAt'])])
            ->filter(fn (array $event): bool => $event['reviewer'] !== null)
            ->sortBy('at')
            ->mapWithKeys(fn (array $event): array => [$this->reviewerKey($event['reviewer']) => $event['at']]);

        return array_values(array_filter($requestedReviewers, fn (array $reviewer): bool => ! (
            $this->isMe($reviewer, $account)
            && $lastRequestedAt->has($this->reviewerKey($reviewer))
            && $lastRequestedAt->get($this->reviewerKey($reviewer))->lt($myLastReviewedAt)
        )));
    }

    /**
     * Whether a requested reviewer is the connected user, or a team they belong to.
     *
     * @param  array{type: string, login?: string, organization?: string, slug?: string, name?: string}  $reviewer
     */
    private function isMe(array $reviewer, GithubAccount $account): bool
    {
        return $reviewer['type'] === 'user'
            ? strcasecmp($reviewer['login'] ?? '', $account->login) === 0
            : $account->isMemberOfTeam($reviewer['organization'] ?? '', $reviewer['slug'] ?? '');
    }

    /**
     * @param  array{type: string, login?: string, organization?: string, slug?: string, name?: string}  $reviewer
     */
    private function reviewerKey(array $reviewer): string
    {
        return strtolower($reviewer['type'] === 'user'
            ? 'user:'.($reviewer['login'] ?? '')
            : 'team:'.($reviewer['organization'] ?? '').'/'.($reviewer['slug'] ?? ''));
    }

    /**
     * Prefer the user's latest approval or change request, falling back to their latest comment-only review.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>|null
     */
    private function myLatestReview(array $node, string $myLogin): ?array
    {
        $findMine = fn (string $connection): ?array => collect($this->nodes($node, $connection))
            ->first(fn (array $review): bool => strcasecmp($review['author']['login'] ?? '', $myLogin) === 0
                && ReviewState::tryFrom($review['state']) !== ReviewState::Pending);

        return $findMine('latestOpinionatedReviews') ?? $findMine('latestReviews');
    }

    /**
     * The nodes of a GraphQL connection, e.g. $node['labels']['nodes'].
     *
     * @param  array<string, mixed>  $node
     * @return list<array<string, mixed>>
     */
    private function nodes(array $node, string $connection): array
    {
        $nodes = $node[$connection]['nodes'] ?? [];

        return is_array($nodes) ? array_values(array_filter($nodes, 'is_array')) : [];
    }
}
