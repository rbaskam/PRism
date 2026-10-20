<?php

namespace Database\Factories;

use App\Enums\AiTaskStatus;
use App\Models\CodeQuestion;
use App\Models\PullRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CodeQuestion>
 */
class CodeQuestionFactory extends Factory
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
            'side' => CodeQuestion::SIDE_NEW,
            'start_line' => 10,
            'end_line' => 14,
            'selected_code' => "+    \$total = 0;\n+    foreach (\$items as \$item) {\n+        \$total += \$item->price;\n+    }",
            'prompt' => 'Is there a better way to write this?',
            'status' => AiTaskStatus::Completed,
            'answer' => 'Use collect($items)->sum("price").',
        ];
    }

    /**
     * Indicate that the question is waiting for Claude.
     */
    public function queued(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AiTaskStatus::Queued,
            'answer' => null,
        ]);
    }
}
