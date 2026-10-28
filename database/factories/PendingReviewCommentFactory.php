<?php

namespace Database\Factories;

use App\Models\PendingReviewComment;
use App\Models\PullRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PendingReviewComment>
 */
class PendingReviewCommentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pull_request_id' => PullRequest::factory(),
            'path' => 'app/Services/'.fake()->word().'.php',
            'side' => 'RIGHT',
            'start_line' => null,
            'line' => fake()->numberBetween(1, 200),
            'body' => fake()->sentence(),
        ];
    }
}
