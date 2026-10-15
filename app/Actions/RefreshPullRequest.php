<?php

namespace App\Actions;

use App\Models\GithubAccount;
use App\Models\PullRequest;
use App\Services\Github\GithubClient;
use App\Services\Github\PullRequestMapper;

/**
 * Re-reads one pull request from GitHub, updates it, and returns its conversation.
 * The conversation is saved so the page can show it instantly until the pull request changes.
 */
class RefreshPullRequest
{
    public function __construct(
        private readonly GithubClient $github,
        private readonly PullRequestMapper $mapper,
    ) {}

    /**
     * @return array{threads: list<array<string, mixed>>, comments: list<array<string, mixed>>, reviews: list<array<string, mixed>>}
     */
    public function handle(PullRequest $pullRequest, GithubAccount $account): array
    {
        $repository = $pullRequest->repository;
        $node = $this->github->pullRequest($repository->owner, $repository->name, $pullRequest->number);

        $conversation = [
            'threads' => collect($this->nodes($node, 'reviewThreads'))->map(fn (array $thread): array => [
                'id' => $thread['id'],
                'is_resolved' => $thread['isResolved'],
                'is_outdated' => $thread['isOutdated'],
                'path' => $thread['path'],
                'line' => $thread['line'] ?? $thread['originalLine'],
                'diff_hunk' => $thread['comments']['nodes'][0]['diffHunk'] ?? null,
                'comments' => array_map($this->comment(...), $thread['comments']['nodes'] ?? []),
            ])->all(),
            'comments' => array_map($this->comment(...), $node['conversation']['nodes'] ?? []),
            'reviews' => collect($this->nodes($node, 'reviews'))
                ->filter(fn (array $review): bool => filled($review['body']) || $review['state'] !== 'COMMENTED')
                ->map(fn (array $review): array => [...$this->comment($review), 'state' => $review['state'], 'created_at' => $review['submittedAt']])
                ->values()
                ->all(),
        ];

        $pullRequest->update([
            ...$this->mapper->attributes($node, $account),
            'conversation' => $conversation,
            'conversation_synced_at' => now(),
        ]);

        return $conversation;
    }

    /**
     * @param  array<string, mixed>  $comment
     * @return array<string, mixed>
     */
    private function comment(array $comment): array
    {
        return [
            'node_id' => $comment['id'],
            'database_id' => $comment['databaseId'] ?? null,
            'author_login' => $comment['author']['login'] ?? 'ghost',
            'author_avatar_url' => $comment['author']['avatarUrl'] ?? null,
            'body' => $comment['body'] ?? '',
            'body_html' => $comment['bodyHTML'] ?? '',
            'url' => $comment['url'] ?? null,
            'created_at' => $comment['createdAt'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<array<string, mixed>>
     */
    private function nodes(array $node, string $connection): array
    {
        $nodes = $node[$connection]['nodes'] ?? [];

        return is_array($nodes) ? array_values(array_filter($nodes, 'is_array')) : [];
    }
}
