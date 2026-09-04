<?php

namespace Database\Factories;

use App\Models\Coach;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Coach>
 */
class CoachFactory extends Factory
{
    protected $model = Coach::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'full_name' => fake()->name(),
            'document_number' => (string) fake()->unique()->numberBetween(1_000_000, 99_999_999),
            'is_active' => true,
        ];
    }
}
