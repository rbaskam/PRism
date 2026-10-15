<?php

namespace App\Http\Resources;

use App\Models\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Repository
 */
class RepositoryResource extends JsonResource
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
            'github_id' => $this->github_id,
            'owner' => $this->owner,
            'name' => $this->name,
            'full_name' => $this->full_name,
            'html_url' => $this->html_url,
            'default_branch' => $this->default_branch,
            'is_private' => $this->is_private,
            'is_tracked' => $this->is_tracked,
            'local_path' => $this->local_path,
            'synced_at' => $this->synced_at?->toIso8601String(),
            'counts' => $this->whenCounted('open_pull_requests_count', fn (): array => $this->pullRequestCounts()),
        ];
    }
}
