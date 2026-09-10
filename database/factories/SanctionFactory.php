<?php

namespace Database\Factories;

use App\Enums\SanctionStatus;
use App\Enums\SanctionType;
use App\Models\MatchEvent;
use App\Models\Player;
use App\Models\Sanction;
use App\Models\Team;
use App\Models\TournamentMatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sanction>
 */
class SanctionFactory extends Factory
{
    protected $model = Sanction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'match_id' => TournamentMatch::factory(),
            'match_event_id' => MatchEvent::factory(),
            'team_id' => Team::factory(),
            'player_id' => Player::factory(),
            'type' => SanctionType::RedCard,
            'status' => SanctionStatus::Pending,
            'matches_banned' => null,
            'fine_amount' => null,
            'resolution_notes' => null,
            'resolved_at' => null,
        ];
    }

    public function resolved(int $matchesBanned = 1): static
    {
        return $this->state(fn (): array => [
            'status' => SanctionStatus::Resolved,
            'matches_banned' => $matchesBanned,
            'resolved_at' => now(),
        ]);
    }
}
