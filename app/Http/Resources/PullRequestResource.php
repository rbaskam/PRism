<?php

namespace App\Http\Resources;

use App\Models\PullRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PullRequest
 */
class PullRequestResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'repository' => $this->whenLoaded('repository', fn (): array => [
                'id' => $this->repository->id,
                'full_name' => $this->repository->full_name,
                'name' => $this->repository->name,
                'owner' => $this->repository->owner,
            ]),
            'number' => $this->number,
            'title' => $this->title,
            'html_url' => $this->html_url,
            'state' => $this->state,
            'is_draft' => $this->is_draft,
            'author_login' => $this->author_login,
            'author_avatar_url' => $this->author_avatar_url,
            'head_ref' => $this->head_ref,
            'base_ref' => $this->base_ref,
            'head_sha' => $this->head_sha,
            'additions' => $this->additions,
            'deletions' => $this->deletions,
            'changed_files' => $this->changed_files,
            'comments_count' => $this->comments_count,
            'review_decision' => $this->review_decision,
            'checks_state' => $this->checks_state,
            'mergeable' => $this->mergeable,
            'attention' => $this->attention()->value,
            'my_review_state' => $this->my_review_state?->value,
            'my_review_submitted_at' => $this->my_review_submitted_at?->toIso8601String(),
            'has_commits_since_my_review' => $this->hasCommitsSinceMyReview(),
            'is_review_requested_from_me' => $this->is_review_requested_from_me,
            'is_review_requested_directly' => $this->is_review_requested_directly,
            'requested_via_teams' => $this->requested_via_teams ?? [],
            'requested_reviewers' => $this->requested_reviewers ?? [],
            'labels' => $this->labels ?? [],
            'is_authored_by_me' => $this->is_authored_by_me,
            'is_assigned_to_me' => $this->is_assigned_to_me,
            'open_findings_count' => $this->whenCounted('open_findings_count'),
            'unread_findings_count' => $this->whenCounted('unread_findings_count'),
            'latest_review_run' => ReviewRunResource::make($this->whenLoaded('latestReviewRun')),
            'created_at' => $this->github_created_at?->toIso8601String(),
            'updated_at' => $this->github_updated_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'synced_at' => $this->synced_at?->toIso8601String(),
        ];
    }
}
