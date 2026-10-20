<?php

namespace App\Models;

use Database\Factories\GithubNotificationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A GitHub notification thread for the connected user, e.g. a review request or a mention.
 *
 * @property int $id
 * @property string $github_id
 * @property int|null $pull_request_id
 * @property string $reason
 * @property string $subject_type
 * @property string $subject_title
 * @property string|null $subject_url
 * @property string|null $html_url
 * @property string $repository_full_name
 * @property bool $is_unread
 * @property Carbon|null $github_updated_at
 * @property Carbon|null $desktop_notified_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class GithubNotification extends Model
{
    /** @use HasFactory<GithubNotificationFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'github_id',
        'pull_request_id',
        'reason',
        'subject_type',
        'subject_title',
        'subject_url',
        'html_url',
        'repository_full_name',
        'is_unread',
        'github_updated_at',
        'desktop_notified_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_unread' => 'boolean',
            'github_updated_at' => 'datetime',
            'desktop_notified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PullRequest, $this>
     */
    public function pullRequest(): BelongsTo
    {
        return $this->belongsTo(PullRequest::class);
    }

    /**
     * The pull request number, when the notification is about a pull request.
     */
    public function pullRequestNumber(): ?int
    {
        if ($this->subject_type !== 'PullRequest' || $this->subject_url === null || ! preg_match('#/pulls/(\d+)$#', $this->subject_url, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }
}
