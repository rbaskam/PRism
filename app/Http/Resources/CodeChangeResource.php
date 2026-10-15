<?php

namespace App\Http\Resources;

use App\Models\CodeChange;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CodeChange
 */
class CodeChangeResource extends JsonResource
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
            'status' => $this->status->value,
            'is_active' => $this->status->isActive(),
            'target' => $this->target->value,
            'destination_branch' => $this->destinationBranch(),
            'instructions' => $this->instructions,
            'finding_ids' => $this->finding_ids ?? [],
            'comments' => $this->comments ?? [],
            'summary' => $this->summary,
            'commits' => $this->commits ?? [],
            'diff' => $this->diff,
            'files_changed' => $this->files_changed,
            'additions' => $this->additions,
            'deletions' => $this->deletions,
            'error' => $this->error,
            'model' => $this->model,
            'cost_usd' => $this->cost_usd,
            'duration_ms' => $this->duration_ms,
            'pushed_branch' => $this->pushed_branch,
            'created_pull_request_url' => $this->created_pull_request_url,
            'started_at' => $this->started_at?->toIso8601String(),
            'pushed_at' => $this->pushed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
