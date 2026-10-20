<?php

namespace App\Models;

use App\Enums\AiTaskStatus;
use Database\Factories\CodeQuestionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A question the user asked Claude about lines they highlighted in a pull request diff.
 * Follow-up questions point at the first question of the thread through parent_id.
 *
 * @property int $id
 * @property int $pull_request_id
 * @property int|null $parent_id
 * @property string $path
 * @property string $side
 * @property int $start_line
 * @property int $end_line
 * @property string $selected_code
 * @property string $prompt
 * @property AiTaskStatus $status
 * @property string|null $answer
 * @property string|null $suggested_code
 * @property string|null $error
 * @property string|null $head_sha
 * @property string|null $model
 * @property float|null $cost_usd
 * @property int|null $duration_ms
 * @property Carbon|null $started_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CodeQuestion extends Model
{
    /** @use HasFactory<CodeQuestionFactory> */
    use HasFactory;

    public const SIDE_NEW = 'RIGHT';

    public const SIDE_OLD = 'LEFT';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'pull_request_id',
        'parent_id',
        'path',
        'side',
        'start_line',
        'end_line',
        'selected_code',
        'prompt',
        'status',
        'answer',
        'suggested_code',
        'error',
        'head_sha',
        'model',
        'cost_usd',
        'duration_ms',
        'started_at',
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
            'start_line' => 'integer',
            'end_line' => 'integer',
            'cost_usd' => 'float',
            'started_at' => 'datetime',
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
     * @return BelongsTo<CodeQuestion, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(CodeQuestion::class, 'parent_id');
    }

    /**
     * @return HasMany<CodeQuestion, $this>
     */
    public function followUps(): HasMany
    {
        return $this->hasMany(CodeQuestion::class, 'parent_id');
    }

    /**
     * Get the first question of this question's thread.
     */
    public function threadRoot(): self
    {
        return $this->parent ?? $this;
    }

    /**
     * Describe the highlighted lines, e.g. "app/Foo.php:12-18".
     */
    public function location(): string
    {
        $lines = $this->start_line === $this->end_line ? $this->start_line : "{$this->start_line}-{$this->end_line}";

        return "{$this->path}:{$lines}";
    }
}
