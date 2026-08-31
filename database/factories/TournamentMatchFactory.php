<?php

namespace Database\Factories;

use App\Enums\MatchStatus;
use App\Models\CompetitionPhase;
use App\Models\Team;
use App\Models\TournamentMatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TournamentMatch>
 */
class TournamentMatchFactory extends Factory
{
    protected $model = TournamentMatch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $phase = CompetitionPhase::factory()->create();

        $homeTeam = Team::factory()->create([
            'category_id' => $phase->category_id,
            'tournament_id' => $phase->tournament_id,
        ]);

        $awayTeam = Team::factory()->create([
            'category_id' => $phase->category_id,
            'tournament_id' => $phase->tournament_id,
        ]);

        return [
            'competition_phase_id' => $phase->id,
            'tournament_id' => $phase->tournament_id,
            'group_id' => null,
            'home_team_id' => $homeTeam->id,
            'away_team_id' => $awayTeam->id,
            'home_score' => null,
            'away_score' => null,
            'status' => MatchStatus::Scheduled,
            'round' => null,
            'scheduled_at' => null,
        ];
    }
}
