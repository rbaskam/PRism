<?php

namespace Database\Factories;

use App\Enums\AlertType;
use App\Models\Alert;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Alert>
 */
class AlertFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => AlertType::ReviewRequests,
            'title' => 'Review requested',
            'body' => 'acme/app: '.fake()->sentence(4),
            'url' => '/pull-requests/'.fake()->numberBetween(1, 999),
            'read_at' => null,
        ];
    }

    /**
     * Indicate that the user has read the alert.
     */
    public function read(): static
    {
        return $this->state(fn (array $attributes) => [
            'read_at' => now(),
        ]);
    }
}
