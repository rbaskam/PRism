<?php

namespace Database\Factories;

use App\Models\Repository;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Repository>
 */
class RepositoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $owner = fake()->userName();
        $name = fake()->unique()->slug(2);

        return [
            'github_id' => fake()->unique()->numberBetween(1000, 9999999),
            'owner' => $owner,
            'name' => $name,
            'full_name' => "{$owner}/{$name}",
            'html_url' => "https://github.com/{$owner}/{$name}",
            'default_branch' => 'main',
            'is_private' => true,
            'is_tracked' => true,
        ];
    }

    /**
     * Indicate that the user stopped tracking the repository.
     */
    public function untracked(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_tracked' => false,
        ]);
    }
}
