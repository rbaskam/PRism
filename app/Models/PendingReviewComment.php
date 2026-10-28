<?php

namespace App\Models;

use Database\Factories\PendingReviewCommentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A comment on lines in a pull request's diff, kept in the app until the user submits their review. It is posted to
 * GitHub with the review, as one of its inline comments.
 *
 * @property int $id
 * @property int $pull_request_id
 * @property string $path
 * @property string $side LEFT for removed lines, RIGHT for added and unchanged ones.
 * @property int|null $start_line
 * @property int $line
 * @property string $body
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PendingReviewComment extends Model
{
    /** @use HasFactory<PendingReviewCommentFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'pull_request_id',
        'path',
        'side',
        'start_line',
        'line',
        'body',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_line' => 'integer',
            'line' => 'integer',
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
     * The comment as GitHub's "create a review" API takes it.
     *
     * @return array{path: string, line: int, side: string, body: string, start_line?: int, start_side?: string}
     */
    public function toReviewComment(): array
    {
        $comment = ['path' => $this->path, 'line' => $this->line, 'side' => $this->side, 'body' => $this->body];

        if ($this->start_line !== null && $this->start_line < $this->line) {
            $comment['start_line'] = $this->start_line;
            $comment['start_side'] = $this->side;
        }

        return $comment;
    }
}
