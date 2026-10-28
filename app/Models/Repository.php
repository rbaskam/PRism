<?php

namespace App\Models;

use App\Enums\ReviewAttention;
use App\Enums\ReviewState;
use Database\Factories\RepositoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $github_id
 * @property string $owner
 * @property string $name
 * @property string $full_name
 * @property string $html_url
 * @property string|null $default_branch
 * @property bool $is_private
 * @property bool $is_tracked
 * @property string|null $local_path
 * @property string|null $review_prompt
 * @property Carbon|null $synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read int|null $open_pull_requests_count
 * @property-read int|null $awaiting_my_review_count
 * @property-read int|null $approved_by_me_count
 */
class Repository extends Model
{
    /** @use HasFactory<RepositoryFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'github_id',
        'owner',
        'name',
        'full_name',
        'html_url',
        'default_branch',
        'is_private',
        'is_tracked',
        'local_path',
        'review_prompt',
        'synced_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_private' => 'boolean',
            'is_tracked' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<PullRequest, $this>
     */
    public function pullRequests(): HasMany
    {
        return $this->hasMany(PullRequest::class);
    }

    /**
     * @return HasMany<PullRequest, $this>
     */
    public function openPullRequests(): HasMany
    {
        return $this->pullRequests()->where('state', PullRequest::STATE_OPEN);
    }

    /**
     * Open pull requests waiting on the user's review, leaving out snoozed ones.
     *
     * @return HasMany<PullRequest, $this>
     */
    public function pullRequestsAwaitingMyReview(): HasMany
    {
        return $this->pullRequests()->awaitingMyReview();
    }

    /**
     * Get the local checkout Claude can read for extra context, if one is configured and exists.
     */
    public function localCheckoutPath(): ?string
    {
        return filled($this->local_path) && is_dir($this->local_path) ? $this->local_path : null;
    }

    /**
     * Whether reviews of this repository's pull requests use its own prompt instead of the app-wide one.
     */
    public function hasCustomReviewPrompt(): bool
    {
        return filled($this->review_prompt);
    }

    /**
     * Scope the query to repositories the user chose to track.
     *
     * @param  Builder<Repository>  $query
     */
    public function scopeTracked(Builder $query): void
    {
        $query->where('is_tracked', true);
    }

    /**
     * Load the open pull request counts shown on repository cards.
     *
     * @param  Builder<Repository>  $query
     */
    public function scopeWithPullRequestCounts(Builder $query): void
    {
        $query->withCount([
            'openPullRequests as open_pull_requests_count',
            'pullRequestsAwaitingMyReview as awaiting_my_review_count',
            'openPullRequests as approved_by_me_count' => fn (Builder $query) => $query
                ->where('is_review_requested_from_me', false)
                ->where('my_review_state', ReviewState::Approved),
        ]);
    }

    /**
     * Get the counts as a keyed array for the frontend.
     *
     * @return array<string, int>
     */
    public function pullRequestCounts(): array
    {
        return [
            'open' => (int) $this->open_pull_requests_count,
            ReviewAttention::AwaitingMyReview->value => (int) $this->awaiting_my_review_count,
            ReviewAttention::Approved->value => (int) $this->approved_by_me_count,
        ];
    }
}
