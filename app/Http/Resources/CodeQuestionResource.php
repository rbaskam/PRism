<?php

namespace App\Http\Resources;

use App\Models\CodeQuestion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CodeQuestion
 */
class CodeQuestionResource extends JsonResource
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
            'parent_id' => $this->parent_id,
            'path' => $this->path,
            'side' => $this->side,
            'start_line' => $this->start_line,
            'end_line' => $this->end_line,
            'location' => $this->location(),
            'selected_code' => $this->selected_code,
            'prompt' => $this->prompt,
            'status' => $this->status->value,
            'is_active' => $this->status->isActive(),
            'answer' => $this->answer,
            'suggested_code' => $this->suggested_code,
            'error' => $this->error,
            'head_sha' => $this->head_sha,
            'model' => $this->model,
            'cost_usd' => $this->cost_usd,
            'duration_ms' => $this->duration_ms,
            'started_at' => $this->started_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
