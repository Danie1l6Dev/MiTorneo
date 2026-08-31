<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Team>
 */
class TeamFactory extends Factory
{
    protected $model = Team::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'tournament_id' => fn (array $attributes) => Category::findOrFail((int) $attributes['category_id'])->tournament_id,
            'name' => fake()->unique()->city().' FC',
            'short_name' => strtoupper(fake()->lexify('???')),
        ];
    }
}
