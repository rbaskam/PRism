<?php

namespace Database\Factories;

use App\Enums\ReviewState;
use App\Models\PullRequest;
use App\Models\Repository;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PullRequest>
 */
class PullRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $number = fake()->unique()->numberBetween(1, 99999);

        return [
            'repository_id' => Repository::factory(),
            'node_id' => 'PR_'.fake()->unique()->regexify('[A-Za-z0-9]{16}'),
            'number' => $number,
            'title' => fake()->sentence(6),
            'html_url' => "https://github.com/acme/app/pull/{$number}",
            'state' => PullRequest::STATE_OPEN,
            'is_draft' => false,
            'author_login' => fake()->userName(),
            'head_ref' => 'feature/'.fake()->slug(2),
            'base_ref' => 'main',
            'head_sha' => fake()->sha1(),
            'additions' => fake()->numberBetween(1, 500),
            'deletions' => fake()->numberBetween(0, 200),
            'changed_files' => fake()->numberBetween(1, 20),
            'requested_via_teams' => [],
            'requested_reviewers' => [],
            'labels' => [],
            'github_created_at' => now()->subDays(2),
            'github_updated_at' => now()->subHour(),
        ];
    }

    /**
     * Indicate that the connected user was asked to review the pull request directly.
     */
    public function awaitingMyReview(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_review_requested_from_me' => true,
            'is_review_requested_directly' => true,
        ]);
    }

    /**
     * Indicate that a team the connected user belongs to was asked to review the pull request.
     */
    public function awaitingTeamReview(string $team = 'acme/reviewers'): static
    {
        return $this->state(fn (array $attributes) => [
            'is_review_requested_from_me' => true,
            'is_review_requested_directly' => false,
            'requested_via_teams' => [$team],
        ]);
    }

    /**
     * Indicate that the user snoozed the pull request, until three days from now unless given.
     */
    public function snoozed(?CarbonInterface $until = null): static
    {
        return $this->state(fn (array $attributes) => [
            'snoozed_until' => $until ?? now()->addDays(3),
        ]);
    }

    /**
     * Indicate that the connected user approved the pull request.
     */
    public function approvedByMe(): static
    {
        return $this->state(fn (array $attributes) => [
            'my_review_state' => ReviewState::Approved,
            'my_review_submitted_at' => now()->subMinutes(30),
            'my_review_commit_sha' => $attributes['head_sha'],
        ]);
    }

    /**
     * Indicate that the connected user opened the pull request.
     */
    public function authoredByMe(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_authored_by_me' => true,
        ]);
    }

    /**
     * Indicate that the pull request has been closed or merged.
     */
    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'state' => PullRequest::STATE_CLOSED,
            'closed_at' => now(),
        ]);
    }
}
