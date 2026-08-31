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

        $matches = $newPhase->matches()->get();
        $this->assertCount(2, $matches);
        $this->assertTrue($matches->every(fn (TournamentMatch $match): bool => $match->round_number === 1 && $match->status === MatchStatus::Scheduled));

        $playingTeamIds = $matches->flatMap(fn (TournamentMatch $match): array => [$match->home_team_id, $match->away_team_id])->sort()->values();
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
        $this->assertNotNull(session('error'));
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
        $this->assertNotNull(session('error'));
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
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['name' => 'Fase de Liga', 'type' => CompetitionPhaseType::League]);
        $teams = Team::factory()->for($tournament)->for($category)->count(6)->create();

        $this->finishedMatch($phase, $teams[0], $teams[1]);
        $this->finishedMatch($phase, $teams[2], $teams[3]);
        $this->finishedMatch($phase, $teams[4], $teams[5]);

        $this->actingAs($user)->post(route('phases.advance.store', $phase), [
            'name' => 'Liga final',
            'type' => CompetitionPhaseType::League->value,
            'qualifiers_per_table' => 4,
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
