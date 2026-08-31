<?php

namespace Database\Factories;

use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tournament>
 */
class TournamentFactory extends Factory
{
    protected $model = Tournament::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => ucfirst(fake()->word()).' '.ucfirst(fake()->word()).' '.fake()->year(),
            'description' => fake()->optional()->sentence(),
            'season' => (string) fake()->year(),
            'status' => TournamentStatus::Draft,
        ];
    }
}
