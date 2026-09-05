<?php

namespace Database\Factories;

use App\Enums\MatchEventType;
use App\Models\MatchEvent;
use App\Models\Player;
use App\Models\Team;
use App\Models\TournamentMatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MatchEvent>
 */
class MatchEventFactory extends Factory
{
    protected $model = MatchEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'match_id' => TournamentMatch::factory(),
            'team_id' => Team::factory(),
            'player_id' => Player::factory(),
            'type' => MatchEventType::Goal,
            'minute' => null,
        ];
    }
}
