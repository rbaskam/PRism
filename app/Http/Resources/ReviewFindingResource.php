<?php

namespace App\Http\Resources;

use App\Models\ReviewFinding;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ReviewFinding
 */
class ReviewFindingResource extends JsonResource
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
            'review_run_id' => $this->review_run_id,
            'last_seen_review_run_id' => $this->last_seen_review_run_id,
            'category' => $this->category->value,
            'category_label' => $this->category->label(),
            'severity' => $this->severity->value,
            'path' => $this->path,
            'line' => $this->line,
            'title' => $this->title,
            'body' => $this->body,
            'suggestion' => $this->suggestion,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_resolved' => $this->resolved_at !== null,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'is_posted' => $this->isPosted(),
            'posted_at' => $this->posted_at?->toIso8601String(),
            'github_comment_url' => $this->github_comment_url,
            'comment_body' => $this->toCommentBody(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
