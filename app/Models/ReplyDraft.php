<?php

namespace App\Models;

use App\Enums\AiTaskStatus;
use App\Enums\CommentKind;
use Database\Factories\ReplyDraftFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A reply to a pull request comment drafted by Claude for the user to edit and send.
 *
 * @property int $id
 * @property int $pull_request_id
 * @property string $comment_node_id
 * @property int|null $comment_database_id
 * @property CommentKind $comment_type
 * @property AiTaskStatus $status
 * @property string|null $instructions
 * @property string|null $body
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ReplyDraft extends Model
{
    /** @use HasFactory<ReplyDraftFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'pull_request_id',
        'comment_node_id',
        'comment_database_id',
        'comment_type',
        'status',
        'instructions',
        'body',
        'error',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'comment_type' => CommentKind::class,
            'status' => AiTaskStatus::class,
        ];
    }

    /**
     * @return BelongsTo<PullRequest, $this>
     */
    public function pullRequest(): BelongsTo
    {
        return $this->belongsTo(PullRequest::class);
    }
}
