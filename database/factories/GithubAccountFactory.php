<?php

namespace Database\Factories;

use App\Enums\GithubTokenSource;
use App\Models\GithubAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GithubAccount>
 */
class GithubAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'github_id' => fake()->unique()->numberBetween(1000, 9999999),
            'login' => fake()->unique()->userName(),
            'name' => fake()->name(),
            'avatar_url' => 'https://avatars.githubusercontent.com/u/1',
            'token' => 'ghp_'.fake()->regexify('[A-Za-z0-9]{36}'),
            'token_source' => GithubTokenSource::PersonalAccessToken,
            'scopes' => ['repo', 'read:org', 'notifications'],
            'teams' => [],
        ];
    }

    /**
     * Indicate that the user belongs to the given team.
     */
    public function inTeam(string $organization, string $slug): static
    {
        return $this->state(fn (array $attributes) => [
            'teams' => [...($attributes['teams'] ?? []), ['organization' => $organization, 'slug' => $slug, 'name' => $slug]],
        ]);
    }
}
