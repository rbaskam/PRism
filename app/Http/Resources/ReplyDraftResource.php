<?php

namespace App\Http\Resources;

use App\Models\ReplyDraft;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ReplyDraft
 */
class ReplyDraftResource extends JsonResource
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
            'comment_node_id' => $this->comment_node_id,
            'status' => $this->status->value,
            'is_active' => $this->status->isActive(),
            'instructions' => $this->instructions,
            'body' => $this->body,
            'error' => $this->error,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
