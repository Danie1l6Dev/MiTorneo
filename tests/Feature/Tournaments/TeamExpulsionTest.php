<?php

namespace Tests\Feature\Tournaments;

use App\Enums\MatchStatus;
use App\Models\Category;
use App\Models\CompetitionPhase;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Services\StandingsService;
use App\Services\TeamExpulsionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamExpulsionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Tournament, 1: Category, 2: CompetitionPhase, 3: Team, 4: Team}
     */
    private function makeLeague(User $user): array
    {
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create();
        $teamA = Team::factory()->for($tournament)->for($category)->create();
        $teamB = Team::factory()->for($tournament)->for($category)->create();

        return [$tournament, $category, $phase, $teamA, $teamB];
    }

    public function test_expelling_a_team_forfeits_its_unplayed_matches_and_awards_the_opponent(): void
    {
        $user = User::factory()->create();
        [$tournament, $category, $phase, $teamA, $teamB] = $this->makeLeague($user);
        $teamC = Team::factory()->for($tournament)->for($category)->create();

        // Already played -- must stay untouched.
        $playedMatch = TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $tournament->id,
            'category_id' => $category->id,
            'home_team_id' => $teamA->id,
            'away_team_id' => $teamB->id,
            'status' => MatchStatus::Finished,
            'home_score' => 2,
            'away_score' => 1,
        ]);

        // Not played yet -- must become a 0-3 walkover loss for teamA.
        $pendingMatch = TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $tournament->id,
            'category_id' => $category->id,
            'home_team_id' => $teamC->id,
            'away_team_id' => $teamA->id,
            'status' => MatchStatus::Scheduled,
        ]);

        $this->actingAs($user)->post(route('tournaments.categories.teams.expel.store', [$tournament, $category, $teamA]), [
            'reason' => 'Agresión al árbitro tras el partido.',
        ])->assertRedirect(route('tournaments.categories.show', [$tournament, $category]));

        $this->assertTrue($teamA->fresh()->isExpelledFrom($tournament->fresh()));
        $this->assertSame('Agresión al árbitro tras el partido.', $teamA->expulsionReasonFor($tournament));

        $playedMatch->refresh();
        $this->assertSame(2, $playedMatch->home_score);
        $this->assertSame(1, $playedMatch->away_score);
        $this->assertFalse($playedMatch->is_walkover);

        $pendingMatch->refresh();
        $this->assertSame(MatchStatus::Finished, $pendingMatch->status);
        $this->assertSame(3, $pendingMatch->home_score);
        $this->assertSame(0, $pendingMatch->away_score);
        $this->assertTrue($pendingMatch->is_walkover);
        $this->assertSame($teamA->id, $pendingMatch->walkover_team_id);

        $tables = app(StandingsService::class)->tablesForPhase($phase->fresh());
        $rows = collect($tables[0]['rows'])->keyBy(fn (array $row) => $row['team']->id);

        $this->assertSame(3, $rows[$teamC->id]['points']);
        $this->assertSame(3, $rows[$teamC->id]['goals_for']);
        $this->assertSame(0, $rows[$teamC->id]['goals_against']);
    }

    public function test_expulsion_does_not_affect_the_same_clubs_team_in_another_category(): void
    {
        $user = User::factory()->create();
        [$tournament, $category, $phase, $teamA, $teamB] = $this->makeLeague($user);

        $otherCategory = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $otherPhase = CompetitionPhase::factory()->for($tournament)->for($otherCategory)->create();
        $siblingTeam = Team::factory()->for($tournament)->for($otherCategory)->create(['club_id' => $teamA->club_id]);
        $siblingOpponent = Team::factory()->for($tournament)->for($otherCategory)->create();

        $siblingMatch = TournamentMatch::factory()->for($otherPhase)->create([
            'tournament_id' => $tournament->id,
            'category_id' => $otherCategory->id,
            'home_team_id' => $siblingTeam->id,
            'away_team_id' => $siblingOpponent->id,
            'status' => MatchStatus::Scheduled,
        ]);

        TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $tournament->id,
            'category_id' => $category->id,
            'home_team_id' => $teamA->id,
            'away_team_id' => $teamB->id,
            'status' => MatchStatus::Scheduled,
        ]);

        $this->actingAs($user)->post(route('tournaments.categories.teams.expel.store', [$tournament, $category, $teamA]))
            ->assertRedirect();

        $siblingMatch->refresh();
        $this->assertSame(MatchStatus::Scheduled, $siblingMatch->status);
        $this->assertFalse($siblingMatch->is_walkover);
        $this->assertFalse($siblingTeam->isExpelledFrom($tournament));
    }

    public function test_reverting_an_expulsion_restores_the_forfeited_matches(): void
    {
        $user = User::factory()->create();
        [$tournament, $category, $phase, $teamA, $teamB] = $this->makeLeague($user);

        $pendingMatch = TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $tournament->id,
            'category_id' => $category->id,
            'home_team_id' => $teamA->id,
            'away_team_id' => $teamB->id,
            'status' => MatchStatus::Scheduled,
        ]);

        app(TeamExpulsionService::class)->expel($teamA, $tournament, 'motivo');
        $this->assertTrue($teamA->isExpelledFrom($tournament));

        $this->actingAs($user)->delete(route('tournaments.categories.teams.expel.destroy', [$tournament, $category, $teamA]))
            ->assertRedirect(route('tournaments.categories.show', [$tournament, $category]));

        $this->assertFalse($teamA->fresh()->isExpelledFrom($tournament->fresh()));

        $pendingMatch->refresh();
        $this->assertSame(MatchStatus::Scheduled, $pendingMatch->status);
        $this->assertNull($pendingMatch->home_score);
        $this->assertNull($pendingMatch->away_score);
        $this->assertFalse($pendingMatch->is_walkover);
        $this->assertNull($pendingMatch->walkover_team_id);
    }

    public function test_both_teams_already_expelled_cancels_the_match_between_them_instead_of_a_walkover(): void
    {
        $user = User::factory()->create();
        [$tournament, $category, $phase, $teamA, $teamB] = $this->makeLeague($user);

        $service = app(TeamExpulsionService::class);
        $service->expel($teamA, $tournament, null);
        $service->expel($teamB, $tournament, null);

        // A fixture between the two, created only AFTER both were already
        // expelled (e.g. a bracket regenerated later) -- neither expel()
        // call above could have seen it. Re-running expel() for one of them
        // is what picks it up.
        $match = TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $tournament->id,
            'category_id' => $category->id,
            'home_team_id' => $teamA->id,
            'away_team_id' => $teamB->id,
            'status' => MatchStatus::Scheduled,
        ]);

        $service->expel($teamA, $tournament, null);

        $match->refresh();
        $this->assertSame(MatchStatus::Cancelled, $match->status);
        $this->assertFalse($match->is_walkover);
    }

    public function test_a_user_cannot_expel_a_team_in_another_users_tournament(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        [$tournament, $category, , $teamA] = $this->makeLeague($owner);

        $this->actingAs($intruder)->post(route('tournaments.categories.teams.expel.store', [$tournament, $category, $teamA]))
            ->assertForbidden();

        $this->assertFalse($teamA->fresh()->isExpelledFrom($tournament));
    }
}
