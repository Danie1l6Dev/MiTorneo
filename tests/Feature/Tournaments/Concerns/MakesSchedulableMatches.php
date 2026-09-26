<?php

namespace Tests\Feature\Tournaments\Concerns;

use App\Enums\CompetitionPhaseType;
use App\Enums\MatchStatus;
use App\Models\Category;
use App\Models\CompetitionPhase;
use App\Models\Referee;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Models\Venue;

/**
 * Builders for the scheduling tests: a tournament with categories, teams
 * reused by name (so the same team can play two matches) and league matches
 * with an optional day/time, cancha and referee.
 */
trait MakesSchedulableMatches
{
    /**
     * @return array{user: User, tournament: Tournament}
     */
    protected function makeSchedulingTournament(?User $user = null): array
    {
        $user ??= User::factory()->create();

        return ['user' => $user, 'tournament' => Tournament::factory()->for($user)->create()];
    }

    protected function makeSchedulingCategory(Tournament $tournament, string $name, int $birthYearTo, ?int $duration = null): Category
    {
        return Category::factory()->for($tournament)->create([
            'name' => $name,
            'birth_year_to' => $birthYearTo,
            'match_duration_minutes' => $duration,
        ]);
    }

    protected function makeSchedulingTeam(Tournament $tournament, Category $category, string $name): Team
    {
        return Team::query()->where(['tournament_id' => $tournament->id, 'category_id' => $category->id, 'name' => mb_strtoupper($name)])->first()
            ?? Team::factory()->for($tournament)->for($category)->create(['name' => $name]);
    }

    protected function makeSchedulingVenue(User $user, string $name): Venue
    {
        return Venue::factory()->create(['user_id' => $user->id, 'name' => $name]);
    }

    protected function makeSchedulingMatch(Tournament $tournament, Category $category, string $home, string $away, int $round, ?string $at = null, ?Venue $venue = null, MatchStatus $status = MatchStatus::Scheduled, ?Referee $referee = null): TournamentMatch
    {
        $phase = CompetitionPhase::query()->where(['tournament_id' => $tournament->id, 'category_id' => $category->id, 'type' => CompetitionPhaseType::League])->first()
            ?? CompetitionPhase::factory()->for($tournament)->for($category)->create(['type' => CompetitionPhaseType::League]);

        return TournamentMatch::factory()->for($phase)->create([
            'home_team_id' => $this->makeSchedulingTeam($tournament, $category, $home)->id,
            'away_team_id' => $this->makeSchedulingTeam($tournament, $category, $away)->id,
            'round_number' => $round,
            'scheduled_at' => $at,
            'venue_id' => $venue?->id,
            'referee_id' => $referee?->id,
            'status' => $status,
        ]);
    }
}
