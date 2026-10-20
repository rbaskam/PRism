<?php

namespace App\Actions;

use App\Enums\FindingStatus;
use App\Enums\ReviewState;
use App\Enums\SubmitReviewEvent;
use App\Models\PullRequest;
use App\Models\ReviewFinding;
use App\Services\Github\GithubClient;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\RequestException;

/**
 * Approves, requests changes on, or comments on a pull request, optionally attaching findings as inline comments.
 */
class SubmitPullRequestReview
{
    public function __construct(private readonly GithubClient $github) {}

    /**
     * @param  Collection<int, ReviewFinding>  $findings
     */
    public function handle(PullRequest $pullRequest, SubmitReviewEvent $event, ?string $body, Collection $findings): void
    {
        $repository = $pullRequest->repository->full_name;
        $inline = $findings->filter(fn (ReviewFinding $finding): bool => $finding->path !== null && $finding->line !== null);
        $general = $findings->diff($inline);

        $reviewBody = collect([$body, ...$general->map(fn (ReviewFinding $finding): string => $finding->toCommentBody())])
            ->filter()
            ->implode("\n\n---\n\n");

        try {
            $review = $this->github->submitReview($repository, $pullRequest->number, $event, $reviewBody, $inline->map(fn (ReviewFinding $finding): array => [
                'path' => $finding->path,
                'line' => $finding->line,
                'body' => $finding->toCommentBody(),
            ])->values()->all());
        } catch (RequestException $exception) {
            if ($inline->isEmpty() || ! GithubClient::isUnprocessable($exception)) {
                throw $exception;
            }

            // A line is outside the diff, so put every finding in the review body instead.
            $reviewBody = collect([$body, ...$findings->map(fn (ReviewFinding $finding): string => ($finding->path ? "`{$finding->path}:{$finding->line}`\n\n" : '').$finding->toCommentBody())])
                ->filter()
                ->implode("\n\n---\n\n");
            $review = $this->github->submitReview($repository, $pullRequest->number, $event, $reviewBody);
        }

        $findings->each(fn (ReviewFinding $finding) => $finding->update([
            'posted_at' => now(),
            'github_comment_url' => $review['html_url'] ?? null,
            'status' => $finding->status === FindingStatus::New ? FindingStatus::Read : $finding->status,
        ]));

        $pullRequest->update([
            'my_review_state' => match ($event) {
                SubmitReviewEvent::Approve => ReviewState::Approved,
                SubmitReviewEvent::RequestChanges => ReviewState::ChangesRequested,
                SubmitReviewEvent::Comment => $pullRequest->my_review_state ?? ReviewState::Commented,
            },
            'my_review_submitted_at' => now(),
            'my_review_commit_sha' => $pullRequest->head_sha,
            'is_review_requested_from_me' => false,
            'is_review_requested_directly' => false,
            'requested_via_teams' => [],
        ]);
    }
}
