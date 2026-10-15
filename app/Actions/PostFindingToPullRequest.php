<?php

namespace App\Actions;

use App\Enums\FindingStatus;
use App\Models\ReviewFinding;
use App\Services\Github\GithubClient;
use Illuminate\Http\Client\RequestException;

/**
 * Writes a finding to its pull request: on the diff line when possible, otherwise as a conversation comment.
 */
class PostFindingToPullRequest
{
    public function __construct(private readonly GithubClient $github) {}

    public function handle(ReviewFinding $finding, string $body): ReviewFinding
    {
        $pullRequest = $finding->pullRequest;
        $repository = $pullRequest->repository->full_name;
        $comment = null;

        if ($finding->path !== null && $finding->line !== null && $pullRequest->head_sha !== null) {
            try {
                $comment = $this->github->createReviewComment($repository, $pullRequest->number, $pullRequest->head_sha, $finding->path, $finding->line, $body);
            } catch (RequestException $exception) {
                if (! GithubClient::isUnprocessable($exception)) {
                    throw $exception;
                }
            }
        }

        if ($comment === null) {
            $location = $finding->path ? "`{$finding->path}".($finding->line ? ":{$finding->line}" : '')."`\n\n" : '';
            $comment = $this->github->createIssueComment($repository, $pullRequest->number, $location.$body);
        }

        $finding->update([
            'github_comment_id' => $comment['id'] ?? null,
            'github_comment_url' => $comment['html_url'] ?? null,
            'posted_at' => now(),
            'status' => $finding->status === FindingStatus::New ? FindingStatus::Read : $finding->status,
        ]);

        return $finding;
    }
}
