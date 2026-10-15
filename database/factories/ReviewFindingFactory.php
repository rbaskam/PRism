<?php

namespace Database\Factories;

use App\Enums\FindingCategory;
use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Models\ReviewFinding;
use App\Models\ReviewRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReviewFinding>
 */
class ReviewFindingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $category = fake()->randomElement(FindingCategory::cases());
        $path = 'app/'.fake()->word().'.php';
        $title = fake()->sentence(5);

        return [
            'review_run_id' => ReviewRun::factory(),
            'pull_request_id' => fn (array $attributes) => ReviewRun::find($attributes['review_run_id'])->pull_request_id,
            'fingerprint' => ReviewFinding::fingerprintFor($path, $category, $title),
            'category' => $category,
            'severity' => fake()->randomElement(FindingSeverity::cases()),
            'path' => $path,
            'line' => fake()->numberBetween(1, 300),
            'title' => $title,
            'body' => fake()->paragraph(),
            'status' => FindingStatus::New,
        ];
    }
}
