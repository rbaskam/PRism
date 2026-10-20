<?php

namespace Database\Factories;

use App\Models\GithubNotification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GithubNotification>
 */
class GithubNotificationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $number = fake()->numberBetween(1, 9999);

        return [
            'github_id' => (string) fake()->unique()->numberBetween(1, 99999999),
            'reason' => 'review_requested',
            'subject_type' => 'PullRequest',
            'subject_title' => fake()->sentence(5),
            'subject_url' => "https://api.github.com/repos/acme/app/pulls/{$number}",
            'html_url' => "https://github.com/acme/app/pull/{$number}",
            'repository_full_name' => 'acme/app',
            'is_unread' => true,
            'github_updated_at' => now(),
        ];
    }
}
