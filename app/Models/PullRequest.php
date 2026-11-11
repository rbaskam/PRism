<?php

namespace App\Models;

use App\Enums\CodeChangeTarget;
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
 * @property bool|null $is_cross_repository
 * @property string|null $head_repository
 * @property bool $maintainer_can_modify
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
 * @property list<string>|null $answered_team_requests
 * @property list<array<string, string>>|null $requested_reviewers
 * @property list<array{name: string, color: string}>|null $labels
 * @property bool $is_authored_by_me
 * @property bool $is_assigned_to_me
 * @property Carbon|null $github_created_at
 * @property Carbon|null $github_updated_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $merged_at
 * @property Carbon|null $synced_at
 * @property array{threads: list<array<string, mixed>>, comments: list<array<string, mixed>>, reviews: list<array<string, mixed>>}|null $conversation
 * @property Carbon|null $conversation_synced_at
 * @property array{files: list<array<string, mixed>>, github_viewed_states: array<string, string>}|null $files_snapshot
 * @property string|null $files_head_sha
 * @property Carbon|null $snoozed_until
 * @property Carbon|null $last_viewed_at
 * @property Carbon|null $unseen_findings_at
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
     * How many recently viewed pull requests the sidebar lists.
     */
    public const RECENTLY_VIEWED_LIMIT = 5;

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
        'is_cross_repository',
        'head_repository',
        'maintainer_can_modify',
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
        'answered_team_requests',
        'requested_reviewers',
        'labels',
        'is_authored_by_me',
        'is_assigned_to_me',
        'github_created_at',
        'github_updated_at',
        'closed_at',
        'merged_at',
        'synced_at',
        'conversation',
        'conversation_synced_at',
        'files_snapshot',
        'files_head_sha',
        'snoozed_until',
        'last_viewed_at',
        'unseen_findings_at',
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
            'is_cross_repository' => 'boolean',
            'maintainer_can_modify' => 'boolean',
            'my_review_state' => ReviewState::class,
            'my_review_submitted_at' => 'datetime',
            'is_review_requested_from_me' => 'boolean',
            'is_review_requested_directly' => 'boolean',
            'requested_via_teams' => 'array',
            'answered_team_requests' => 'array',
            'requested_reviewers' => 'array',
            'labels' => 'array',
            'is_authored_by_me' => 'boolean',
            'is_assigned_to_me' => 'boolean',
            'github_created_at' => 'datetime',
            'github_updated_at' => 'datetime',
            'closed_at' => 'datetime',
            'merged_at' => 'datetime',
            'synced_at' => 'datetime',
            'conversation' => 'array',
            'conversation_synced_at' => 'datetime',
            'files_snapshot' => 'array',
            'snoozed_until' => 'datetime',
            'last_viewed_at' => 'datetime',
            'unseen_findings_at' => 'datetime',
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
     * Scope the query to the pull requests the user opened in the app, in tracked repositories, most recent first.
     *
     * @param  Builder<PullRequest>  $query
     */
    public function scopeRecentlyViewed(Builder $query): void
    {
        $query->whereNotNull('last_viewed_at')
            ->whereHas('repository', fn (Builder $query) => $query->tracked())
            ->orderByDesc('last_viewed_at')
            ->orderByDesc('id');
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
     * Scope the query to pull requests the connected user is involved in. Ones a teammate reviewed for one of the
     * user's teams count only when asked for: GitHub drops a team's request once any member reviews, which leaves
     * nothing for the user to do.
     *
     * @param  Builder<PullRequest>  $query
     */
    public function scopeInvolvingMe(Builder $query, bool $withAnsweredTeamRequests = false): void
    {
        $query->where(fn (Builder $query) => $query
            ->where('is_authored_by_me', true)
            ->orWhere('is_review_requested_from_me', true)
            ->orWhere('is_assigned_to_me', true)
            ->orWhereNotNull('my_review_state')
            ->when($withAnsweredTeamRequests, fn (Builder $query) => $query->orWhereJsonLength('answered_team_requests', '>', 0)));
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
     * Why Claude's changes can't go to a target, or null when they can.
     *
     * A pull request from a fork brings code from someone who can't push to this repository, so PRism never pushes
     * that code to a branch here: a fix goes onto the fork's own branch, and only when its author lets maintainers
     * edit it.
     */
    public function codeChangeRefusal(CodeChangeTarget $target): ?string
    {
        if ($this->is_cross_repository === null) {
            return 'PRism needs to refresh this pull request from GitHub first. Try again in a moment.';
        }

        if (! $this->is_cross_repository) {
            return null;
        }

        return match ($target) {
            CodeChangeTarget::NewPullRequest => 'This pull request comes from a fork, so PRism won\'t push its code to a new branch in this repository.',
            CodeChangeTarget::PullRequestBranch => match (true) {
                $this->head_repository === null => 'The fork this pull request comes from no longer exists, so there is no branch to push to.',
                ! $this->maintainer_can_modify => "This pull request comes from a fork ({$this->head_repository}) whose author doesn't allow edits from maintainers.",
                default => null,
            },
        };
    }

    /**
     * Remember that the user just opened the pull request, for the sidebar's "Recently viewed" list, and that they've
     * now seen any new AI findings. Its updated_at is left alone, since nothing about the pull request itself changed.
     */
    public function markViewed(): void
    {
        static::withoutTimestamps(fn () => $this->update(['last_viewed_at' => now(), 'unseen_findings_at' => null]));
    }

    /**
     * Point out the pull request in lists until the user opens it, because an AI review found something new.
     */
    public function markFindingsUnseen(): void
    {
        static::withoutTimestamps(fn () => $this->update(['unseen_findings_at' => now()]));
    }

    /**
     * The user saw the findings, e.g. they arrived on the pull request page while it was open.
     */
    public function markFindingsSeen(): void
    {
        if ($this->unseen_findings_at !== null) {
            static::withoutTimestamps(fn () => $this->update(['unseen_findings_at' => null]));
        }
    }

    public function hasUnseenFindings(): bool
    {
        return $this->unseen_findings_at !== null;
    }

    /**
     * Get the "owner/name#123" reference for the pull request.
     */
    public function reference(): string
    {
        return $this->repository->full_name.'#'.$this->number;
    }
}
