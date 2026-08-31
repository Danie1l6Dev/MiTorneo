<?php

namespace Database\Factories;

use App\Enums\CompetitionPhaseType;
use App\Models\Category;
use App\Models\CompetitionPhase;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompetitionPhase>
 */
class CompetitionPhaseFactory extends Factory
{
    protected $model = CompetitionPhase::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'tournament_id' => fn (array $attributes) => Category::findOrFail((int) $attributes['category_id'])->tournament_id,
            'name' => fake()->randomElement(['Liga', 'Semifinales', 'Final']),
            'type' => CompetitionPhaseType::League,
            'order' => 0,
        ];
    }
}
