<?php

namespace App\Models;

use App\Enums\AlertType;
use Database\Factories\AlertFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An alert PRism raised, e.g. a review request or Claude finishing a review. Alerts are listed under the bell in the
 * header, whether or not a desktop notification was shown for them.
 *
 * @property int $id
 * @property AlertType $type
 * @property string $title
 * @property string|null $body
 * @property string|null $url Where clicking the alert goes: a path in the app, or a github.com link.
 * @property int|null $pull_request_id
 * @property Carbon|null $read_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Alert extends Model
{
    /** @use HasFactory<AlertFactory> */
    use HasFactory, MassPrunable;

    /**
     * How long alerts are kept.
     */
    public const KEEP_DAYS = 30;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'type',
        'title',
        'body',
        'url',
        'pull_request_id',
        'read_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AlertType::class,
            'read_at' => 'datetime',
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
     * Scope the query to alerts the user hasn't read.
     *
     * @param  Builder<Alert>  $query
     */
    public function scopeUnread(Builder $query): void
    {
        $query->whereNull('read_at');
    }

    /**
     * Alerts older than KEEP_DAYS are deleted by `model:prune`.
     *
     * @return Builder<Alert>
     */
    public function prunable(): Builder
    {
        return Alert::query()->where('created_at', '<', now()->subDays(self::KEEP_DAYS));
    }
}
