<?php

namespace Database\Factories;

use App\Models\CompetitionPhase;
use App\Models\Group;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Group>
 */
class GroupFactory extends Factory
{
    protected $model = Group::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'competition_phase_id' => CompetitionPhase::factory(),
            'tournament_id' => fn (array $attributes) => CompetitionPhase::findOrFail((int) $attributes['competition_phase_id'])->tournament_id,
            'name' => 'Grupo '.fake()->randomLetter(),
            'order' => 0,
        ];
    }
}
