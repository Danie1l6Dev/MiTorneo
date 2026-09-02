<?php

namespace Tests\Feature\Tournaments;

use App\Enums\CompetitionPhaseType;
use App\Enums\MatchStatus;
use App\Enums\ScheduleFormat;
use App\Models\Category;
use App\Models\CompetitionPhase;
use App\Models\Group;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseAdvancementTest extends TestCase
{
    use RefreshDatabase;

    private function finishedMatch(CompetitionPhase $phase, Team $home, Team $away, ?Group $group = null): TournamentMatch
    {
        return TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $phase->tournament_id,
            'category_id' => $phase->category_id,
            'group_id' => $group?->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'home_score' => 1,
            'away_score' => 0,
            'status' => MatchStatus::Finished,
        ]);
    }

    public function test_advancing_to_an_elimination_phase_draws_matches_for_a_power_of_two_number_of_qualifiers(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['name' => 'Fase de Liga', 'type' => CompetitionPhaseType::League, 'order' => 0]);
        $teams = Team::factory()->for($tournament)->for($category)->count(4)->create();

        $this->finishedMatch($phase, $teams[0], $teams[1]);
        $this->finishedMatch($phase, $teams[2], $teams[3]);

        $response = $this->actingAs($user)->post(route('phases.advance.store', $phase), [
            'name' => 'Semifinales',
            'type' => CompetitionPhaseType::Knockout->value,
            'qualifiers_per_table' => 4,
        ]);

        $newPhase = CompetitionPhase::where('name', 'Semifinales')->firstOrFail();
        $response->assertRedirect(route('phases.show', $newPhase));

        $this->assertSame(CompetitionPhaseType::Knockout, $newPhase->type);
        $this->assertSame(1, $newPhase->order);
        $this->assertSame(4, $newPhase->teams()->count());

        // 4 qualifiers builds the full bracket, not just round 1: 2 semifinal
        // matches with real teams, plus the final already created and waiting
        // on its two sides to be resolved once the semifinals are played.
        $matches = $newPhase->matches()->get();
        $this->assertCount(3, $matches);

        $round1 = $matches->where('round_number', 1);
        $this->assertCount(2, $round1);
        $this->assertTrue($round1->every(fn (TournamentMatch $match): bool => $match->status === MatchStatus::Scheduled && $match->home_team_id !== null && $match->away_team_id !== null));

        $round2 = $matches->where('round_number', 2);
        $this->assertCount(1, $round2);
        $final = $round2->first();
        $this->assertNull($final->home_team_id);
        $this->assertNull($final->away_team_id);

        $playingTeamIds = $round1->flatMap(fn (TournamentMatch $match): array => [$match->home_team_id, $match->away_team_id])->sort()->values();
        $this->assertSame($teams->pluck('id')->sort()->values()->all(), $playingTeamIds->all());
    }

    public function test_an_elimination_phase_rejects_a_qualifier_count_that_is_not_a_power_of_two(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['name' => 'Fase de Liga', 'type' => CompetitionPhaseType::League]);
        $teams = Team::factory()->for($tournament)->for($category)->count(3)->create();

        $this->finishedMatch($phase, $teams[0], $teams[1]);

        $response = $this->actingAs($user)->post(route('phases.advance.store', $phase), [
            'name' => 'Semifinales',
            'type' => CompetitionPhaseType::Knockout->value,
            'qualifiers_per_table' => 3,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('qualifiers_per_table');
        $this->assertDatabaseMissing('competition_phases', ['name' => 'Semifinales']);
    }

    public function test_advancing_to_a_league_phase_does_not_require_a_power_of_two_and_creates_no_matches_yet(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->usingGroups()->create();
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['name' => 'Fase de Liga', 'type' => CompetitionPhaseType::League]);
        $groupA = Group::factory()->for($tournament)->for($category)->create();
        $groupB = Group::factory()->for($tournament)->for($category)->create();
        $teamsA = Team::factory()->for($tournament)->for($category)->for($groupA)->count(3)->create();
        $teamsB = Team::factory()->for($tournament)->for($category)->for($groupB)->count(3)->create();

        $this->finishedMatch($phase, $teamsA[0], $teamsA[1], $groupA);
        $this->finishedMatch($phase, $teamsB[0], $teamsB[1], $groupB);

        $response = $this->actingAs($user)->post(route('phases.advance.store', $phase), [
            'name' => 'Liga de clasificados',
            'type' => CompetitionPhaseType::League->value,
            'qualifiers_per_table' => 3,
        ]);

        $newPhase = CompetitionPhase::where('name', 'Liga de clasificados')->firstOrFail();
        $response->assertRedirect(route('phases.show', $newPhase));

        $this->assertSame(6, $newPhase->teams()->count());
        $this->assertSame(0, $newPhase->matches()->count());
    }

    public function test_cannot_advance_when_matches_are_not_finished(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['name' => 'Fase de Liga', 'type' => CompetitionPhaseType::League]);
        $teams = Team::factory()->for($tournament)->for($category)->count(2)->create();

        TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $tournament->id,
            'category_id' => $category->id,
            'home_team_id' => $teams[0]->id,
            'away_team_id' => $teams[1]->id,
            'status' => MatchStatus::Scheduled,
        ]);

        $response = $this->actingAs($user)->post(route('phases.advance.store', $phase), [
            'name' => 'Semifinales',
            'type' => CompetitionPhaseType::Knockout->value,
            'qualifiers_per_table' => 2,
        ]);

        $response->assertRedirect(route('phases.show', $phase));
        $this->assertNotNull(session('error'));
        $this->assertDatabaseMissing('competition_phases', ['name' => 'Semifinales']);
    }

    public function test_cannot_advance_from_a_non_league_phase(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['name' => 'Cuartos', 'type' => CompetitionPhaseType::Knockout]);

        $response = $this->actingAs($user)->get(route('phases.advance.create', $phase));

        $response->assertRedirect(route('phases.show', $phase));
        $this->assertNotNull(session('error'));
    }

    public function test_qualifiers_per_table_cannot_exceed_the_teams_available(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['name' => 'Fase de Liga', 'type' => CompetitionPhaseType::League]);
        $teams = Team::factory()->for($tournament)->for($category)->count(2)->create();

        $this->finishedMatch($phase, $teams[0], $teams[1]);

        $response = $this->actingAs($user)->post(route('phases.advance.store', $phase), [
            'name' => 'Semifinales',
            'type' => CompetitionPhaseType::Knockout->value,
            'qualifiers_per_table' => 5,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('qualifiers_per_table');
        $this->assertDatabaseMissing('competition_phases', ['name' => 'Semifinales']);
    }

    public function test_semifinal_does_not_require_qualifiers_per_table_and_uses_a_fixed_count(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->usingGroups()->create();
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['name' => 'Fase de Liga', 'type' => CompetitionPhaseType::League]);
        $groupA = Group::factory()->for($tournament)->for($category)->create();
        $groupB = Group::factory()->for($tournament)->for($category)->create();
        $teamsA = Team::factory()->for($tournament)->for($category)->for($groupA)->count(3)->create();
        $teamsB = Team::factory()->for($tournament)->for($category)->for($groupB)->count(3)->create();

        $this->finishedMatch($phase, $teamsA[0], $teamsA[1], $groupA);
        $this->finishedMatch($phase, $teamsB[0], $teamsB[1], $groupB);

        // Semifinal: no qualifiers_per_table sent at all, 2 per table implied.
        $response = $this->actingAs($user)->post(route('phases.advance.store', $phase), [
            'name' => 'Semifinales',
            'type' => CompetitionPhaseType::Semifinal->value,
        ]);

        $semifinal = CompetitionPhase::where('name', 'Semifinales')->firstOrFail();
        $response->assertRedirect(route('phases.show', $semifinal));
        $this->assertSame(4, $semifinal->teams()->count());
        // The 2 semifinal matches, plus the final already created and pending.
        $this->assertSame(3, $semifinal->matches()->count());
        $this->assertSame(1, $semifinal->matches()->whereNull('home_team_id')->count());
    }

    public function test_final_does_not_require_qualifiers_per_table_and_uses_a_fixed_count(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->usingGroups()->create();
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['name' => 'Fase de Liga', 'type' => CompetitionPhaseType::League]);
        $groupA = Group::factory()->for($tournament)->for($category)->create();
        $groupB = Group::factory()->for($tournament)->for($category)->create();
        $teamsA = Team::factory()->for($tournament)->for($category)->for($groupA)->count(3)->create();
        $teamsB = Team::factory()->for($tournament)->for($category)->for($groupB)->count(3)->create();

        $this->finishedMatch($phase, $teamsA[0], $teamsA[1], $groupA);
        $this->finishedMatch($phase, $teamsB[0], $teamsB[1], $groupB);

        // Final: no qualifiers_per_table sent at all, 1 per table implied.
        $response = $this->actingAs($user)->post(route('phases.advance.store', $phase), [
            'name' => 'Final',
            'type' => CompetitionPhaseType::Final->value,
        ]);

        $final = CompetitionPhase::where('name', 'Final')->firstOrFail();
        $response->assertRedirect(route('phases.show', $final));
        $this->assertSame(2, $final->teams()->count());
        $this->assertSame(1, $final->matches()->count());
    }

    public function test_cannot_advance_the_same_league_phase_twice(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['name' => 'Fase de Liga', 'type' => CompetitionPhaseType::League, 'order' => 1]);
        $teams = Team::factory()->for($tournament)->for($category)->count(4)->create();

        $this->finishedMatch($phase, $teams[0], $teams[1]);
        $this->finishedMatch($phase, $teams[2], $teams[3]);

        $this->actingAs($user)->post(route('phases.advance.store', $phase), [
            'name' => 'Eliminatoria',
            'type' => CompetitionPhaseType::Knockout->value,
            'qualifiers_per_table' => 4,
        ])->assertRedirect();

        $this->assertSame(1, CompetitionPhase::where('category_id', $category->id)->where('order', '>', 1)->count());

        // Even though the league is still fully finished, it already has a
        // next phase (the champion is decided inside that bracket already):
        // trying to advance it again must not spawn a second, conflicting one.
        $createResponse = $this->actingAs($user)->get(route('phases.advance.create', $phase));
        $createResponse->assertRedirect(route('phases.show', $phase));
        $this->assertNotNull(session('error'));

        $storeResponse = $this->actingAs($user)->post(route('phases.advance.store', $phase), [
            'name' => 'Otra eliminatoria',
            'type' => CompetitionPhaseType::Knockout->value,
            'qualifiers_per_table' => 4,
        ]);

        $storeResponse->assertRedirect(route('phases.show', $phase));
        $this->assertNotNull(session('error'));
        $this->assertDatabaseMissing('competition_phases', ['name' => 'Otra eliminatoria']);

        // Deleting the phase that was already created from it re-opens advancing.
        CompetitionPhase::where('category_id', $category->id)->where('order', '>', 1)->delete();

        $this->actingAs($user)->post(route('phases.advance.store', $phase), [
            'name' => 'Otra eliminatoria',
            'type' => CompetitionPhaseType::Knockout->value,
            'qualifiers_per_table' => 4,
        ])->assertRedirect(route('phases.show', CompetitionPhase::where('name', 'Otra eliminatoria')->firstOrFail()));
    }

    public function test_qualifiers_per_table_cannot_be_sent_for_semifinal_or_final(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['name' => 'Fase de Liga', 'type' => CompetitionPhaseType::League]);
        $teams = Team::factory()->for($tournament)->for($category)->count(2)->create();
        $this->finishedMatch($phase, $teams[0], $teams[1]);

        $response = $this->actingAs($user)->post(route('phases.advance.store', $phase), [
            'name' => 'Semifinales',
            'type' => CompetitionPhaseType::Semifinal->value,
            'qualifiers_per_table' => 2,
        ]);

        $response->assertSessionHasErrors('qualifiers_per_table');
        $this->assertDatabaseMissing('competition_phases', ['name' => 'Semifinales']);
    }

    public function test_semifinal_is_rejected_when_a_table_has_fewer_than_two_teams(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->usingGroups()->create();
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['name' => 'Fase de Liga', 'type' => CompetitionPhaseType::League]);
        $groupA = Group::factory()->for($tournament)->for($category)->create();
        $groupB = Group::factory()->for($tournament)->for($category)->create();
        $teamsA = Team::factory()->for($tournament)->for($category)->for($groupA)->count(2)->create();
        $teamB = Team::factory()->for($tournament)->for($category)->for($groupB)->create();

        $this->finishedMatch($phase, $teamsA[0], $teamsA[1], $groupA);

        $response = $this->actingAs($user)->post(route('phases.advance.store', $phase), [
            'name' => 'Semifinales',
            'type' => CompetitionPhaseType::Semifinal->value,
        ]);

        $response->assertSessionHasErrors('qualifiers_per_table');
        $this->assertDatabaseMissing('competition_phases', ['name' => 'Semifinales']);
    }

    public function test_another_user_cannot_advance_someone_elses_phase(): void
    {
        $owner = User::factory()->create();
        $tournament = Tournament::factory()->for($owner)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['name' => 'Fase de Liga', 'type' => CompetitionPhaseType::League]);
        $teams = Team::factory()->for($tournament)->for($category)->count(2)->create();
        $this->finishedMatch($phase, $teams[0], $teams[1]);

        $intruder = User::factory()->create();

        $this->actingAs($intruder)->get(route('phases.advance.create', $phase))->assertForbidden();
        $this->actingAs($intruder)->post(route('phases.advance.store', $phase), [
            'name' => 'Semifinales',
            'type' => CompetitionPhaseType::Knockout->value,
            'qualifiers_per_table' => 2,
        ])->assertForbidden();
    }

    public function test_the_new_phases_roster_scopes_schedule_generation_and_standings_to_only_the_qualified_teams(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        // A League phase can only spawn another League phase when it has
        // more than one standings table to unify (see canAdvanceToLeague);
        // a category without groups feeds from a single table, so this
        // needs 2 groups to exercise "advance to a new League" here.
        $category = Category::factory()->for($tournament)->usingGroups()->create();
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['name' => 'Fase de Liga', 'type' => CompetitionPhaseType::League]);
        $groupA = Group::factory()->for($tournament)->for($category)->create();
        $groupB = Group::factory()->for($tournament)->for($category)->create();
        $teamsA = Team::factory()->for($tournament)->for($category)->for($groupA)->count(3)->create();
        $teamsB = Team::factory()->for($tournament)->for($category)->for($groupB)->count(3)->create();

        $this->finishedMatch($phase, $teamsA[0], $teamsA[1], $groupA);
        $this->finishedMatch($phase, $teamsB[0], $teamsB[1], $groupB);

        $this->actingAs($user)->post(route('phases.advance.store', $phase), [
            'name' => 'Liga final',
            'type' => CompetitionPhaseType::League->value,
            'qualifiers_per_table' => 2,
        ]);

        $newPhase = CompetitionPhase::where('name', 'Liga final')->firstOrFail();
        $rosterIds = $newPhase->teams()->pluck('teams.id')->sort()->values()->all();

        $this->actingAs($user)->post(route('phases.schedule.store', $newPhase), [
            'format' => ScheduleFormat::SingleRound->value,
        ]);

        $generatedTeamIds = $newPhase->matches()
            ->get()
            ->flatMap(fn (TournamentMatch $match): array => [$match->home_team_id, $match->away_team_id])
            ->unique()
            ->sort()
            ->values()
            ->all();

        $this->assertSame($rosterIds, $generatedTeamIds);
        $this->assertSame(6, $newPhase->matches()->count());

        $response = $this->actingAs($user)->get(route('phases.show', $newPhase));
        $response->assertOk();

        $standings = $response->viewData('standings');
        $this->assertCount(1, $standings);
        $this->assertCount(4, $standings[0]['rows']);
    }
}
