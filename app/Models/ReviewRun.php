<?php

namespace App\Models;

use App\Enums\AiTaskStatus;
use Database\Factories\ReviewRunFactory;
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
