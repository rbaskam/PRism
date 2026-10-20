<?php

namespace Database\Factories;

use App\Enums\AiTaskStatus;
use App\Models\PullRequest;
use App\Models\ReviewRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReviewRun>
 */
class ReviewRunFactory extends Factory
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
            'status' => AiTaskStatus::Completed,
            'head_sha' => fake()->sha1(),
            'prompt' => 'Review the pull request.',
            'summary' => fake()->sentence(),
            'verdict' => 'comment',
            'started_at' => now()->subMinutes(3),
            'finished_at' => now(),
        ];
    }

    /**
     * Indicate that the run is waiting for a queue worker.
     */
    public function queued(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AiTaskStatus::Queued,
            'summary' => null,
            'verdict' => null,
            'started_at' => null,
            'finished_at' => null,
        ]);
    }
}
