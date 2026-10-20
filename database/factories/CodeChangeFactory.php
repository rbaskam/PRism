<?php

namespace Database\Factories;

use App\Enums\CodeChangeStatus;
use App\Enums\CodeChangeTarget;
use App\Models\CodeChange;
use App\Models\PullRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CodeChange>
 */
class CodeChangeFactory extends Factory
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
            'status' => CodeChangeStatus::Ready,
            'target' => CodeChangeTarget::PullRequestBranch,
            'instructions' => 'Fix the failing null check.',
            'checkout_path' => sys_get_temp_dir(),
            'worktree_path' => sys_get_temp_dir().'/prism-worktree',
            'branch' => 'prism/change',
            'base_sha' => fake()->sha1(),
            'head_sha' => fake()->sha1(),
            'summary' => 'Added a null check.',
            'commits' => [['sha' => fake()->sha1(), 'message' => 'Add a null check']],
            'diff' => "diff --git a/app/Foo.php b/app/Foo.php\n--- a/app/Foo.php\n+++ b/app/Foo.php\n@@ -1 +1 @@\n-old\n+new",
            'files_changed' => 1,
            'additions' => 1,
            'deletions' => 1,
        ];
    }

    /**
     * Indicate that Claude has not started yet.
     */
    public function queued(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CodeChangeStatus::Queued,
            'worktree_path' => null,
            'branch' => null,
            'head_sha' => null,
            'summary' => null,
            'commits' => null,
            'diff' => null,
        ]);
    }
}
