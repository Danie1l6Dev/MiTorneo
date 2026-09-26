<?php

namespace Database\Factories;

use App\Enums\RosterEndReason;
use App\Enums\RosterStartReason;
use App\Models\Player;
use App\Models\PlayerTeamHistory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlayerTeamHistory>
 */
class PlayerTeamHistoryFactory extends Factory
{
    protected $model = PlayerTeamHistory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'player_id' => Player::factory(),
            'team_id' => null,
            'club_id' => null,
            'club_name' => fake()->city().' FC',
            'team_name' => fake()->city().' FC',
            'category_name' => 'SUB-13',
            'group_name' => null,
            'jersey_number' => null,
            'started_on' => '2026-01-10',
            'ended_on' => null,
            'start_reason' => RosterStartReason::Registered,
            'end_reason' => null,
            'is_estimated' => false,
            'notes' => null,
        ];
    }

    public function ended(string $on = '2026-06-30', RosterEndReason $reason = RosterEndReason::Removed): static
    {
        return $this->state(fn (): array => ['ended_on' => $on, 'end_reason' => $reason]);
    }
}
