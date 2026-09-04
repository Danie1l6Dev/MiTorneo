<?php

namespace Database\Factories;

use App\Models\Player;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Player>
 */
class PlayerFactory extends Factory
{
    protected $model = Player::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'full_name' => fake()->name(),
            'document_number' => (string) fake()->unique()->numberBetween(1_000_000, 99_999_999),
            'jersey_number' => fake()->unique()->numberBetween(1, 99),
            'is_active' => true,
        ];
    }
}
