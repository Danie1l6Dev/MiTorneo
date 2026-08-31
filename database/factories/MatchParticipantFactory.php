<?php

namespace Database\Factories;

use App\Enums\MatchParticipantSide;
use App\Enums\MatchParticipantSourceType;
use App\Models\MatchParticipant;
use App\Models\Team;
use App\Models\TournamentMatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MatchParticipant>
 */
class MatchParticipantFactory extends Factory
{
    protected $model = MatchParticipant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'match_id' => TournamentMatch::factory(),
            'side' => MatchParticipantSide::Home,
            'type' => MatchParticipantSourceType::Team,
            'team_id' => Team::factory(),
            'source_match_id' => null,
            'source_phase_id' => null,
            'source_group_id' => null,
            'position' => null,
        ];
    }

    public function matchWinner(TournamentMatch $sourceMatch): self
    {
        return $this->state(fn (): array => [
            'type' => MatchParticipantSourceType::MatchWinner,
            'team_id' => null,
            'source_match_id' => $sourceMatch->id,
        ]);
    }

    public function standingPosition(int $position): self
    {
        return $this->state(fn (): array => [
            'type' => MatchParticipantSourceType::StandingPosition,
            'team_id' => null,
            'position' => $position,
        ]);
    }
}
