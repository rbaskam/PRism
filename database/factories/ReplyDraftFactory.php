<?php

namespace Database\Factories;

use App\Enums\AiTaskStatus;
use App\Enums\CommentKind;
use App\Models\PullRequest;
use App\Models\ReplyDraft;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReplyDraft>
 */
class ReplyDraftFactory extends Factory
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
            'comment_node_id' => 'PRRC_'.fake()->regexify('[A-Za-z0-9]{12}'),
            'comment_database_id' => fake()->numberBetween(1000, 9999999),
            'comment_type' => CommentKind::ReviewThread,
            'status' => AiTaskStatus::Completed,
            'body' => fake()->paragraph(),
        ];
    }
}
