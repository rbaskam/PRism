<?php

namespace App\Http\Resources;

use App\Models\ReviewRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ReviewRun
 */
class ReviewRunResource extends JsonResource
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
            'head_sha' => $this->head_sha,
            'model' => $this->model,
            'prompt' => $this->prompt,
            'summary' => $this->summary,
            'verdict' => $this->verdict,
            'new_findings_count' => $this->new_findings_count,
            'repeated_findings_count' => $this->repeated_findings_count,
            'resolved_findings_count' => $this->resolved_findings_count,
            'error' => $this->error,
            'cost_usd' => $this->cost_usd,
            'duration_ms' => $this->duration_ms,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
