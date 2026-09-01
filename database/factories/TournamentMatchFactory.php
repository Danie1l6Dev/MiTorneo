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
        return [
            // Lazy factory references: Laravel only invokes these (and so only
            // creates rows for them) when the caller doesn't already override
            // the key -- e.g. via ->for($phase) or an explicit home_team_id in
            // create([...]), which is how every real usage in this app calls
            // this factory. An eager create() here would silently leave behind
            // an orphaned phase/team on every single call regardless of
            // overrides, which is exactly what used to happen and made the
            // test suite flaky (an orphaned phase could randomly land on a
            // name like "Semifinales" and collide with what a test actually
            // meant to assert against).
            'competition_phase_id' => CompetitionPhase::factory(),
            'tournament_id' => fn (array $attributes) => CompetitionPhase::findOrFail((int) $attributes['competition_phase_id'])->tournament_id,
            'category_id' => fn (array $attributes) => CompetitionPhase::findOrFail((int) $attributes['competition_phase_id'])->category_id,
            'group_id' => null,
            'league_schedule_id' => null,
            'home_team_id' => fn (array $attributes) => Team::factory()->create([
                'category_id' => $attributes['category_id'],
                'tournament_id' => $attributes['tournament_id'],
            ])->id,
            'away_team_id' => fn (array $attributes) => Team::factory()->create([
                'category_id' => $attributes['category_id'],
                'tournament_id' => $attributes['tournament_id'],
            ])->id,
            'home_score' => null,
            'away_score' => null,
            'status' => MatchStatus::Scheduled,
            'round_number' => null,
            'scheduled_at' => null,
        ];
    }
}
