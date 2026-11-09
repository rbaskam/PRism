<?php

namespace App\Models;

use App\Enums\AiTaskStatus;
use Database\Factories\ReviewRunFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A single run of Claude Code reviewing a pull request.
 *
 * @property int $id
 * @property int $pull_request_id
 * @property AiTaskStatus $status
 * @property string|null $head_sha
 * @property string|null $model
 * @property string $prompt
 * @property string|null $summary
 * @property string|null $verdict
 * @property int $new_findings_count
 * @property int $repeated_findings_count
 * @property int $resolved_findings_count
 * @property string|null $raw_output
 * @property string|null $error
 * @property float|null $cost_usd
 * @property int|null $duration_ms
 * @property string|null $session_id
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ReviewRun extends Model
{
    /** @use HasFactory<ReviewRunFactory> */
    use HasFactory;

    /**
     * How often a running review touches `updated_at` to show it's still going.
     */
    public const HEARTBEAT_SECONDS = 30;

    /**
     * A running review that hasn't shown it's still going for this long was stopped, usually because PRism quit while
     * Claude was reviewing. Long enough to cover fetching a large pull request before Claude starts.
     */
    public const INTERRUPTED_AFTER_MINUTES = 5;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'pull_request_id',
        'status',
        'head_sha',
        'model',
        'prompt',
        'summary',
        'verdict',
        'new_findings_count',
        'repeated_findings_count',
        'resolved_findings_count',
        'raw_output',
        'error',
        'cost_usd',
        'duration_ms',
        'session_id',
        'started_at',
        'finished_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'raw_output',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AiTaskStatus::class,
            'cost_usd' => 'float',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Runs marked as running whose worker has stopped, so they would otherwise show as reviewing forever.
     *
     * @param  Builder<ReviewRun>  $query
     */
    public function scopeInterrupted(Builder $query): void
    {
        $query->where('status', AiTaskStatus::Running)
            ->where('updated_at', '<', now()->subMinutes(self::INTERRUPTED_AFTER_MINUTES));
    }

    /**
     * Stop the review if it hasn't finished. The worker notices within a second or two and stops Claude.
     */
    public function cancel(): void
    {
        if ($this->status->isActive()) {
            $this->update(['status' => AiTaskStatus::Cancelled, 'finished_at' => now()]);
        }
    }

    /**
     * @return BelongsTo<PullRequest, $this>
     */
    public function pullRequest(): BelongsTo
    {
        return $this->belongsTo(PullRequest::class);
    }

    /**
     * @return HasMany<ReviewFinding, $this>
     */
    public function findings(): HasMany
    {
        return $this->hasMany(ReviewFinding::class);
    }
}
