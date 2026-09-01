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
            // A 3-value pool made it easy for an unrelated, unnamed phase to
            // randomly land on a name like "Semifinales" that some other test
            // explicitly asserts against (competition_phases.name isn't
            // unique, so nothing stops it from colliding). fake()->unique()
            // rules that out entirely for the life of the test run.
            'name' => 'Fase '.fake()->unique()->word(),
            'type' => CompetitionPhaseType::League,
            'order' => 0,
        ];
    }
}
