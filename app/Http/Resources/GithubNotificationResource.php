<?php

namespace App\Http\Resources;

use App\Models\GithubNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin GithubNotification
 */
class GithubNotificationResource extends JsonResource
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
            'reason' => $this->reason,
            'subject_type' => $this->subject_type,
            'subject_title' => $this->subject_title,
            'html_url' => $this->html_url,
            'repository_full_name' => $this->repository_full_name,
            'pull_request_id' => $this->pull_request_id,
            'is_unread' => $this->is_unread,
            'updated_at' => $this->github_updated_at?->toIso8601String(),
        ];
    }
}
