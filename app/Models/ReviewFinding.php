<?php

namespace App\Models;

use App\Enums\FindingCategory;
use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use Database\Factories\ReviewFindingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An issue Claude raised while reviewing a pull request, and what the user decided to do with it.
 *
 * @property int $id
 * @property int $pull_request_id
 * @property int $review_run_id
 * @property int|null $last_seen_review_run_id
 * @property string $fingerprint
 * @property FindingCategory $category
 * @property FindingSeverity $severity
 * @property string|null $path
 * @property int|null $line
 * @property string $title
 * @property string $body
 * @property string|null $suggestion
 * @property FindingStatus $status
 * @property Carbon|null $resolved_at
 * @property int|null $github_comment_id
 * @property string|null $github_comment_url
 * @property Carbon|null $posted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ReviewFinding extends Model
{
    /** @use HasFactory<ReviewFindingFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'pull_request_id',
        'review_run_id',
        'last_seen_review_run_id',
        'fingerprint',
        'category',
        'severity',
        'path',
        'line',
        'title',
        'body',
        'suggestion',
        'status',
        'resolved_at',
        'github_comment_id',
        'github_comment_url',
        'posted_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => FindingCategory::class,
            'severity' => FindingSeverity::class,
            'status' => FindingStatus::class,
            'line' => 'integer',
            'resolved_at' => 'datetime',
            'posted_at' => 'datetime',
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
     * @return BelongsTo<ReviewRun, $this>
     */
    public function reviewRun(): BelongsTo
    {
        return $this->belongsTo(ReviewRun::class);
    }

    /**
     * Build the stable fingerprint used to recognise the same finding across review runs.
     */
    public static function fingerprintFor(?string $path, FindingCategory $category, string $title): string
    {
        $normalisedTitle = Str::of($title)->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->squish();

        return sha1(implode('|', [$path ?? '', $category->value, $normalisedTitle]));
    }

    /**
     * Determine whether the finding has been written to the pull request on GitHub.
     */
    public function isPosted(): bool
    {
        return $this->posted_at !== null;
    }

    /**
     * Render the finding as a GitHub comment body.
     */
    public function toCommentBody(): string
    {
        $body = "**{$this->category->label()} · ".Str::ucfirst($this->severity->value)."** — {$this->title}\n\n{$this->body}";

        if (filled($this->suggestion)) {
            $body .= "\n\n**Suggested fix:**\n{$this->suggestion}";
        }

        return $body;
    }
}
