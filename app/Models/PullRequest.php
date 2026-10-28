<?php

namespace App\Models;

use App\Enums\FindingStatus;
use App\Enums\ReviewAttention;
use App\Enums\ReviewState;
use Database\Factories\PullRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $repository_id
 * @property string $node_id
 * @property int $number
 * @property string $title
 * @property string|null $body_html
 * @property string $html_url
 * @property string $state
 * @property bool $is_draft
 * @property string|null $author_login
 * @property string|null $author_avatar_url
 * @property string|null $head_ref
 * @property string|null $base_ref
 * @property string|null $head_sha
 * @property int $additions
 * @property int $deletions
 * @property int $changed_files
 * @property int $comments_count
 * @property string|null $review_decision
 * @property string|null $checks_state
 * @property string|null $mergeable
 * @property ReviewState|null $my_review_state
 * @property Carbon|null $my_review_submitted_at
 * @property string|null $my_review_commit_sha
 * @property bool $is_review_requested_from_me
 * @property bool $is_review_requested_directly
 * @property list<string>|null $requested_via_teams
 * @property list<array<string, string>>|null $requested_reviewers
 * @property list<array{name: string, color: string}>|null $labels
 * @property bool $is_authored_by_me
 * @property bool $is_assigned_to_me
 * @property Carbon|null $github_created_at
 * @property Carbon|null $github_updated_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $synced_at
 * @property array{threads: list<array<string, mixed>>, comments: list<array<string, mixed>>, reviews: list<array<string, mixed>>}|null $conversation
 * @property Carbon|null $conversation_synced_at
 * @property array{files: list<array<string, mixed>>, github_viewed_states: array<string, string>}|null $files_snapshot
 * @property string|null $files_head_sha
 * @property Carbon|null $snoozed_until
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PullRequest extends Model
{
    /** @use HasFactory<PullRequestFactory> */
    use HasFactory;

    public const STATE_OPEN = 'open';

    public const STATE_CLOSED = 'closed';

    /**
     * How long closed pull requests stay in the lists (behind the "Recently closed" filter).
     */
    public const RECENTLY_CLOSED_DAYS = 14;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'repository_id',
        'node_id',
        'number',
        'title',
        'body_html',
        'html_url',
        'state',
        'is_draft',
        'author_login',
        'author_avatar_url',
        'head_ref',
        'base_ref',
        'head_sha',
        'additions',
        'deletions',
        'changed_files',
        'comments_count',
        'review_decision',
        'checks_state',
        'mergeable',
        'my_review_state',
        'my_review_submitted_at',
        'my_review_commit_sha',
        'is_review_requested_from_me',
        'is_review_requested_directly',
        'requested_via_teams',
        'requested_reviewers',
        'labels',
        'is_authored_by_me',
        'is_assigned_to_me',
        'github_created_at',
        'github_updated_at',
        'closed_at',
        'synced_at',
        'conversation',
        'conversation_synced_at',
        'files_snapshot',
        'files_head_sha',
        'snoozed_until',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_draft' => 'boolean',
            'my_review_state' => ReviewState::class,
            'my_review_submitted_at' => 'datetime',
            'is_review_requested_from_me' => 'boolean',
            'is_review_requested_directly' => 'boolean',
            'requested_via_teams' => 'array',
            'requested_reviewers' => 'array',
            'labels' => 'array',
            'is_authored_by_me' => 'boolean',
            'is_assigned_to_me' => 'boolean',
            'github_created_at' => 'datetime',
            'github_updated_at' => 'datetime',
            'closed_at' => 'datetime',
            'synced_at' => 'datetime',
            'conversation' => 'array',
            'conversation_synced_at' => 'datetime',
            'files_snapshot' => 'array',
            'snoozed_until' => 'datetime',
        ];
    }

    /**
     * A snoozed pull request wakes up as soon as new commits are pushed to it.
     */
    protected static function booted(): void
    {
        static::saving(function (PullRequest $pullRequest): void {
            if ($pullRequest->snoozed_until !== null && $pullRequest->isDirty('head_sha') && $pullRequest->getOriginal('head_sha') !== null) {
                $pullRequest->snoozed_until = null;
            }
        });
    }

    /**
     * @return BelongsTo<Repository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    /**
     * @return HasMany<ReviewRun, $this>
     */
    public function reviewRuns(): HasMany
    {
        return $this->hasMany(ReviewRun::class);
    }

    /**
     * @return HasOne<ReviewRun, $this>
     */
    public function latestReviewRun(): HasOne
    {
        return $this->hasOne(ReviewRun::class)->latestOfMany();
    }

    /**
     * @return HasMany<ReviewFinding, $this>
     */
    public function findings(): HasMany
    {
        return $this->hasMany(ReviewFinding::class);
    }

    /**
     * Comments on lines kept until the user submits their review.
     *
     * @return HasMany<PendingReviewComment, $this>
     */
    public function pendingReviewComments(): HasMany
    {
        return $this->hasMany(PendingReviewComment::class);
    }

    /**
     * @return HasMany<CodeQuestion, $this>
     */
    public function codeQuestions(): HasMany
    {
        return $this->hasMany(CodeQuestion::class);
    }

    /**
     * @return HasMany<CodeChange, $this>
     */
    public function codeChanges(): HasMany
    {
        return $this->hasMany(CodeChange::class);
    }

    /**
     * @return HasMany<PullRequestFileView, $this>
     */
    public function fileViews(): HasMany
    {
        return $this->hasMany(PullRequestFileView::class);
    }

    /**
     * @return HasMany<ReplyDraft, $this>
     */
    public function replyDrafts(): HasMany
    {
        return $this->hasMany(ReplyDraft::class);
    }

    /**
     * Scope the query to open pull requests.
     *
     * @param  Builder<PullRequest>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->where('state', self::STATE_OPEN);
    }

    /**
     * Scope the query to open pull requests and ones closed or merged recently, so their AI reviews stay reachable.
     *
     * @param  Builder<PullRequest>  $query
     */
    public function scopeOpenOrRecentlyClosed(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query
            ->where('state', self::STATE_OPEN)
            ->orWhere('closed_at', '>=', now()->subDays(self::RECENTLY_CLOSED_DAYS)));
    }

    /**
     * Scope the query to pull requests the user hasn't snoozed, or whose snooze has ended.
     *
     * @param  Builder<PullRequest>  $query
     */
    public function scopeNotSnoozed(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now()));
    }

    /**
     * Scope the query to open pull requests waiting on the user's review, leaving out snoozed ones. This is what the
     * sidebar, the Dock badge and the repository cards count.
     *
     * @param  Builder<PullRequest>  $query
     */
    public function scopeAwaitingMyReview(Builder $query): void
    {
        $query->open()->where('is_review_requested_from_me', true)->notSnoozed();
    }

    /**
     * Scope the query to pull requests the connected user is involved in.
     *
     * @param  Builder<PullRequest>  $query
     */
    public function scopeInvolvingMe(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query
            ->where('is_authored_by_me', true)
            ->orWhere('is_review_requested_from_me', true)
            ->orWhere('is_assigned_to_me', true)
            ->orWhereNotNull('my_review_state'));
    }

    /**
     * Load the finding counts shown in pull request lists.
     *
     * @param  Builder<PullRequest>  $query
     */
    public function scopeWithFindingCounts(Builder $query): void
    {
        $query->withCount([
            'findings as open_findings_count' => fn (Builder $query) => $query
                ->whereNull('resolved_at')
                ->whereIn('status', [FindingStatus::New, FindingStatus::Read]),
            'findings as unread_findings_count' => fn (Builder $query) => $query
                ->whereNull('resolved_at')
                ->where('status', FindingStatus::New),
        ]);
    }

    public function isSnoozed(): bool
    {
        return $this->snoozed_until?->isFuture() ?? false;
    }

    /**
     * Work out where the pull request stands from the connected user's point of view.
     */
    public function attention(): ReviewAttention
    {
        if ($this->is_authored_by_me) {
            return ReviewAttention::Authored;
        }

        if ($this->is_review_requested_from_me) {
            return ReviewAttention::AwaitingMyReview;
        }

        return match ($this->my_review_state) {
            ReviewState::Approved => ReviewAttention::Approved,
            ReviewState::ChangesRequested => ReviewAttention::ChangesRequested,
            ReviewState::Commented => ReviewAttention::Commented,
            default => ReviewAttention::None,
        };
    }

    /**
     * Determine whether commits were pushed after the user's latest review.
     */
    public function hasCommitsSinceMyReview(): bool
    {
        return $this->my_review_commit_sha !== null
            && $this->head_sha !== null
            && $this->my_review_commit_sha !== $this->head_sha;
    }

    /**
     * Whether the saved conversation is still current: nothing has happened on GitHub since it was fetched.
     */
    public function hasCurrentConversation(): bool
    {
        return $this->conversation !== null
            && $this->conversation_synced_at !== null
            && ($this->github_updated_at === null || $this->conversation_synced_at->gte($this->github_updated_at));
    }

    /**
     * Get the "owner/name#123" reference for the pull request.
     */
    public function reference(): string
    {
        return $this->repository->full_name.'#'.$this->number;
    }
}
