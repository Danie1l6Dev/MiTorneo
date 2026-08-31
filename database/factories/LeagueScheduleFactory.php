<?php

namespace Database\Factories;

use App\Enums\ScheduleFormat;
use App\Models\CompetitionPhase;
use App\Models\LeagueSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeagueSchedule>
 */
class LeagueScheduleFactory extends Factory
{
    protected $model = LeagueSchedule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'competition_phase_id' => CompetitionPhase::factory(),
            'tournament_id' => fn (array $attributes) => CompetitionPhase::findOrFail((int) $attributes['competition_phase_id'])->tournament_id,
            'group_id' => null,
            'format' => ScheduleFormat::SingleRound,
            'generated_at' => now(),
        ];
    }
}
