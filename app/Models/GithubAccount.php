<?php

namespace App\Models;

use App\Enums\GithubTokenSource;
use Database\Factories\GithubAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The GitHub account the desktop app is connected to. The app is single-user, so there is at most one row.
 *
 * @property int $id
 * @property int $github_id
 * @property string $login
 * @property string|null $name
 * @property string|null $avatar_url
 * @property string $token
 * @property GithubTokenSource $token_source
 * @property list<string>|null $scopes
 * @property list<array{organization: string, slug: string, name: string}>|null $teams
 * @property Carbon|null $teams_synced_at
 * @property string|null $notifications_last_modified
 * @property Carbon|null $notifications_polled_at
 * @property list<string>|null $untracked_review_requests
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class GithubAccount extends Model
{
    /** @use HasFactory<GithubAccountFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'github_id',
        'login',
        'name',
        'avatar_url',
        'token',
        'token_source',
        'scopes',
        'teams',
        'teams_synced_at',
        'notifications_last_modified',
        'notifications_polled_at',
        'untracked_review_requests',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'token_source' => GithubTokenSource::class,
            'scopes' => 'array',
            'teams' => 'array',
            'teams_synced_at' => 'datetime',
            'notifications_polled_at' => 'datetime',
            'untracked_review_requests' => 'array',
        ];
    }

    /**
     * Get the connected account, if GitHub has been connected.
     */
    public static function current(): ?self
    {
        return static::query()->latest('id')->first();
    }

    /**
     * Determine whether the user belongs to the given team.
     */
    public function isMemberOfTeam(string $organization, string $slug): bool
    {
        return collect($this->teams ?? [])->contains(
            fn (array $team): bool => strcasecmp($team['organization'], $organization) === 0
                && strcasecmp($team['slug'], $slug) === 0,
        );
    }
}
