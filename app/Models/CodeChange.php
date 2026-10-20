<?php

namespace App\Models;

use App\Enums\CodeChangeStatus;
use App\Enums\CodeChangeTarget;
use Database\Factories\CodeChangeFactory;
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
     * The branch the changes will be pushed to.
     */
    public function destinationBranch(): string
    {
        return $this->target === CodeChangeTarget::PullRequestBranch
            ? (string) $this->pullRequest->head_ref
            : "prism/pr-{$this->pullRequest->number}-fixes-{$this->id}";
    }
}
