<?php

namespace Database\Factories;

use App\Models\Referee;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Referee>
 */
class RefereeFactory extends Factory
{
    protected $model = Referee::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'full_name' => fake()->name(),
            'document_number' => (string) fake()->unique()->numberBetween(1_000_000, 99_999_999),
        ];
    }
}
