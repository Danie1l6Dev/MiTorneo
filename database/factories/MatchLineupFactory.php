<?php

namespace Database\Factories;

use App\Models\MatchLineup;
use App\Models\Player;
use App\Models\Team;
use App\Models\TournamentMatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MatchLineup>
 */
class MatchLineupFactory extends Factory
{
    protected $model = MatchLineup::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'match_id' => TournamentMatch::factory(),
            'team_id' => Team::factory(),
            'player_id' => Player::factory(),
        ];
    }
}
