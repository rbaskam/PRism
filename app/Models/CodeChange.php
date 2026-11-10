<?php

namespace App\Models;

use App\Enums\CodeChangeStatus;
use App\Enums\CodeChangeTarget;
use Database\Factories\CodeChangeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Changes Claude made to a pull request's code in a worktree, waiting for the user to push or discard them.
 *
 * @property int $id
 * @property int $pull_request_id
 * @property CodeChangeStatus $status
 * @property CodeChangeTarget $target
 * @property string|null $instructions
 * @property list<int>|null $finding_ids
 * @property list<array{author: string, body: string, path?: string|null, line?: int|null}>|null $comments
 * @property string $checkout_path
 * @property string|null $worktree_path
 * @property string|null $branch
 * @property string|null $base_sha
 * @property string|null $head_sha
 * @property string|null $summary
 * @property list<array{sha: string, message: string}>|null $commits
 * @property string|null $diff
 * @property bool $diff_truncated
 * @property int $files_changed
 * @property int $additions
 * @property int $deletions
 * @property string|null $error
 * @property string|null $model
 * @property float|null $cost_usd
 * @property int|null $duration_ms
 * @property string|null $pushed_branch
 * @property string|null $created_pull_request_url
 * @property Carbon|null $started_at
 * @property Carbon|null $pushed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CodeChange extends Model
{
    /** @use HasFactory<CodeChangeFactory> */
    use HasFactory;

    /**
     * Claude never works on a change for longer than this, so one still running after it was interrupted.
     */
    public const INTERRUPTED_AFTER_MINUTES = 60;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'pull_request_id',
        'status',
        'target',
        'instructions',
        'finding_ids',
        'comments',
        'checkout_path',
        'worktree_path',
        'branch',
        'base_sha',
        'head_sha',
        'summary',
        'commits',
        'diff',
        'diff_truncated',
        'files_changed',
        'additions',
        'deletions',
        'error',
        'model',
        'cost_usd',
        'duration_ms',
        'pushed_branch',
        'created_pull_request_url',
        'started_at',
        'pushed_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CodeChangeStatus::class,
            'target' => CodeChangeTarget::class,
            'finding_ids' => 'array',
            'comments' => 'array',
            'commits' => 'array',
            'diff_truncated' => 'boolean',
            'cost_usd' => 'float',
            'started_at' => 'datetime',
            'pushed_at' => 'datetime',
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
     * Scope the query to changes whose worktree and branch must stay: ones waiting to be pushed or being pushed, and
     * ones Claude is still working on. A change still "running" an hour after it last changed was interrupted (no job
     * runs that long), so its worktree is left over.
     *
     * @param  Builder<CodeChange>  $query
     */
    public function scopeKeepingWorktree(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query
            ->whereIn('status', [CodeChangeStatus::Ready, CodeChangeStatus::Pushing])
            ->orWhere(fn (Builder $query) => $query
                ->whereIn('status', [CodeChangeStatus::Queued, CodeChangeStatus::Running])
                ->where('updated_at', '>=', now()->subMinutes(self::INTERRUPTED_AFTER_MINUTES))));
    }

    /**
     * The branch the changes will be pushed to.
     */
    public function destinationBranch(): string
    {
        return $this->target === CodeChangeTarget::PullRequestBranch
            ? (string) $this->pullRequest->head_ref
            : "prism/pr-{$this->pullRequest->number}-fixes-{$this->id}";
    }

    /**
     * The "owner/name" of the repository the changes will be pushed to: the fork a pull request comes from when they
     * go onto its branch, otherwise the pull request's own repository.
     */
    public function destinationRepository(): string
    {
        $pullRequest = $this->pullRequest;

        return $this->target === CodeChangeTarget::PullRequestBranch && $pullRequest->is_cross_repository && $pullRequest->head_repository !== null
            ? $pullRequest->head_repository
            : $pullRequest->repository->full_name;
    }

    /**
     * Why these changes can't be pushed, or null when they can (pushing must also be allowed in settings).
     */
    public function pushRefusal(): ?string
    {
        if ($this->diff_truncated) {
            return 'This change is too big for PRism to show in full, and only changes you\'ve seen can be pushed. Discard it and ask Claude for a smaller change.';
        }

        if ($this->head_sha === null) {
            return 'PRism has no record of which commit to push. Discard these changes and ask Claude again.';
        }

        return $this->pullRequest->codeChangeRefusal($this->target);
    }
}
