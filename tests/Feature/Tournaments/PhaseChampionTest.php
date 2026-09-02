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

class PhaseChampionTest extends TestCase
{
    use RefreshDatabase;

    private function finishedMatch(CompetitionPhase $phase, Team $home, Team $away, int $homeScore, int $awayScore, ?Group $group = null): TournamentMatch
    {
        return TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $phase->tournament_id,
            'category_id' => $phase->category_id,
            'group_id' => $group?->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'home_score' => $homeScore,
            'away_score' => $awayScore,
            'status' => MatchStatus::Finished,
        ]);
    }

    public function test_the_top_team_can_be_declared_champion_of_a_single_table_league(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['type' => CompetitionPhaseType::League, 'order' => 1]);
        $teams = Team::factory()->for($tournament)->for($category)->count(4)->create();

        // teams[0] wins both its matches, clearly finishing 1st.
        $this->finishedMatch($phase, $teams[0], $teams[1], 3, 0);
        $this->finishedMatch($phase, $teams[0], $teams[2], 2, 0);
        $this->finishedMatch($phase, $teams[1], $teams[3], 1, 1);
        $this->finishedMatch($phase, $teams[2], $teams[3], 1, 1);

        $response = $this->actingAs($user)->post(route('phases.champion.store', $phase));

        $response->assertRedirect(route('phases.show', $phase));
        $this->assertSame($teams[0]->id, $phase->fresh()->champion_team_id);

        $showResponse = $this->actingAs($user)->get(route('phases.show', $phase));
        $showResponse->assertOk();
        $this->assertSame($teams[0]->id, $showResponse->viewData('champion')->id);
    }

    public function test_champion_cannot_be_declared_from_a_league_with_more_than_one_group(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->usingGroups()->create();
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['type' => CompetitionPhaseType::League, 'order' => 1]);
        $groupA = Group::factory()->for($tournament)->for($category)->create();
        $groupB = Group::factory()->for($tournament)->for($category)->create();
        $teamsA = Team::factory()->for($tournament)->for($category)->for($groupA)->count(2)->create();
        $teamsB = Team::factory()->for($tournament)->for($category)->for($groupB)->count(2)->create();

        $this->finishedMatch($phase, $teamsA[0], $teamsA[1], 1, 0, $groupA);
        $this->finishedMatch($phase, $teamsB[0], $teamsB[1], 1, 0, $groupB);

        $response = $this->actingAs($user)->post(route('phases.champion.store', $phase));

        $response->assertRedirect(route('phases.show', $phase));
        $this->assertNotNull(session('error'));
        $this->assertNull($phase->fresh()->champion_team_id);
    }

    public function test_champion_cannot_be_declared_while_matches_are_still_pending(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['type' => CompetitionPhaseType::League, 'order' => 1]);
        $teams = Team::factory()->for($tournament)->for($category)->count(2)->create();

        TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $tournament->id,
            'category_id' => $category->id,
            'home_team_id' => $teams[0]->id,
            'away_team_id' => $teams[1]->id,
            'status' => MatchStatus::Scheduled,
        ]);

        $response = $this->actingAs($user)->post(route('phases.champion.store', $phase));

        $response->assertRedirect(route('phases.show', $phase));
        $this->assertNotNull(session('error'));
        $this->assertNull($phase->fresh()->champion_team_id);
    }

    public function test_declaring_a_champion_blocks_creating_a_next_phase_and_declaring_it_again(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['type' => CompetitionPhaseType::League, 'order' => 1]);
        $teams = Team::factory()->for($tournament)->for($category)->count(2)->create();
        $this->finishedMatch($phase, $teams[0], $teams[1], 1, 0);

        $this->actingAs($user)->post(route('phases.champion.store', $phase))->assertRedirect(route('phases.show', $phase));

        // Declaring it again is a no-op guarded by isAlreadyResolved().
        $secondDeclare = $this->actingAs($user)->post(route('phases.champion.store', $phase));
        $secondDeclare->assertRedirect(route('phases.show', $phase));
        $this->assertNotNull(session('error'));

        // Creating a further phase from this league is blocked too.
        $advanceResponse = $this->actingAs($user)->post(route('phases.advance.store', $phase), [
            'name' => 'Eliminatoria',
            'type' => CompetitionPhaseType::Knockout->value,
            'qualifiers_per_table' => 2,
        ]);
        $advanceResponse->assertRedirect(route('phases.show', $phase));
        $this->assertNotNull(session('error'));
        $this->assertDatabaseMissing('competition_phases', ['name' => 'Eliminatoria']);
    }

    public function test_removing_a_declared_champion_reopens_advancing(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['type' => CompetitionPhaseType::League, 'order' => 1]);
        $teams = Team::factory()->for($tournament)->for($category)->count(2)->create();
        $this->finishedMatch($phase, $teams[0], $teams[1], 1, 0);

        $this->actingAs($user)->post(route('phases.champion.store', $phase));
        $this->assertNotNull($phase->fresh()->champion_team_id);

        $this->actingAs($user)->delete(route('phases.champion.destroy', $phase))->assertRedirect(route('phases.show', $phase));
        $this->assertNull($phase->fresh()->champion_team_id);

        $this->actingAs($user)->post(route('phases.champion.store', $phase))->assertRedirect(route('phases.show', $phase));
        $this->assertNotNull($phase->fresh()->champion_team_id);
    }

    public function test_a_single_table_league_can_only_advance_to_a_knockout_type_not_another_league(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['type' => CompetitionPhaseType::League, 'order' => 1]);
        $teams = Team::factory()->for($tournament)->for($category)->count(4)->create();

        $this->finishedMatch($phase, $teams[0], $teams[1], 1, 0);
        $this->finishedMatch($phase, $teams[2], $teams[3], 1, 0);

        $leagueResponse = $this->actingAs($user)->post(route('phases.advance.store', $phase), [
            'name' => 'Otra liga',
            'type' => CompetitionPhaseType::League->value,
            'qualifiers_per_table' => 4,
        ]);
        $leagueResponse->assertSessionHasErrors('type');
        $this->assertDatabaseMissing('competition_phases', ['name' => 'Otra liga']);

        $knockoutResponse = $this->actingAs($user)->post(route('phases.advance.store', $phase), [
            'name' => 'Eliminatoria',
            'type' => CompetitionPhaseType::Knockout->value,
            'qualifiers_per_table' => 4,
        ]);
        $knockoutResponse->assertRedirect(route('phases.show', CompetitionPhase::where('name', 'Eliminatoria')->firstOrFail()));
    }

    public function test_a_multi_group_league_can_still_advance_to_another_league(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->usingGroups()->create();
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['type' => CompetitionPhaseType::League, 'order' => 1]);
        $groupA = Group::factory()->for($tournament)->for($category)->create();
        $groupB = Group::factory()->for($tournament)->for($category)->create();
        $teamsA = Team::factory()->for($tournament)->for($category)->for($groupA)->count(2)->create();
        $teamsB = Team::factory()->for($tournament)->for($category)->for($groupB)->count(2)->create();

        $this->finishedMatch($phase, $teamsA[0], $teamsA[1], 1, 0, $groupA);
        $this->finishedMatch($phase, $teamsB[0], $teamsB[1], 1, 0, $groupB);

        $response = $this->actingAs($user)->post(route('phases.advance.store', $phase), [
            'name' => 'Liga de clasificados',
            'type' => CompetitionPhaseType::League->value,
            'qualifiers_per_table' => 2,
        ]);

        $newPhase = CompetitionPhase::where('name', 'Liga de clasificados')->firstOrFail();
        $response->assertRedirect(route('phases.show', $newPhase));
    }

    public function test_the_schedule_cannot_be_deleted_while_a_champion_is_declared(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['type' => CompetitionPhaseType::League, 'order' => 1]);
        $teams = Team::factory()->for($tournament)->for($category)->count(2)->create();

        $this->actingAs($user)->post(route('phases.schedule.store', $phase), [
            'format' => ScheduleFormat::SingleRound->value,
        ]);

        $match = $phase->matches()->firstOrFail();
        $match->update(['status' => MatchStatus::Finished, 'home_score' => 1, 'away_score' => 0]);

        $this->actingAs($user)->post(route('phases.champion.store', $phase));
        $this->assertNotNull($phase->fresh()->champion_team_id);

        // The schedule is the sustento of the declared champion: it can't be
        // deleted until the champion is removed first.
        $this->actingAs($user)->delete(route('phases.schedule.destroy', $phase))
            ->assertRedirect(route('phases.show', $phase));
        $this->assertNotNull(session('error'));
        $this->assertNotNull($phase->fresh()->champion_team_id);
        $this->assertSame(1, $phase->matches()->count());

        // Once the champion is removed, the schedule can be deleted again.
        $this->actingAs($user)->delete(route('phases.champion.destroy', $phase));
        $this->actingAs($user)->delete(route('phases.schedule.destroy', $phase))
            ->assertRedirect(route('phases.show', $phase));
        $this->assertSame(0, $phase->matches()->count());
    }

    public function test_the_schedule_cannot_be_deleted_while_a_next_phase_already_exists(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['type' => CompetitionPhaseType::League, 'order' => 1]);
        $teams = Team::factory()->for($tournament)->for($category)->count(4)->create();

        $this->finishedMatch($phase, $teams[0], $teams[1], 1, 0);
        $this->finishedMatch($phase, $teams[2], $teams[3], 1, 0);

        $this->actingAs($user)->post(route('phases.advance.store', $phase), [
            'name' => 'Eliminatoria',
            'type' => CompetitionPhaseType::Knockout->value,
            'qualifiers_per_table' => 4,
        ]);
        $nextPhase = CompetitionPhase::where('name', 'Eliminatoria')->firstOrFail();

        // The league's matches here weren't generated through phases.schedule.store
        // (see finishedMatch()), so there's no LeagueSchedule row to delete --
        // but the guard must still reject before that becomes relevant.
        $response = $this->actingAs($user)->delete(route('phases.schedule.destroy', $phase));
        $response->assertRedirect(route('phases.show', $phase));
        $this->assertNotNull(session('error'));
        $this->assertSame(2, $phase->matches()->count());

        // Deleting the next phase re-opens deleting the league's own schedule.
        $nextPhase->delete();

        $this->actingAs($user)->delete(route('phases.schedule.destroy', $phase))
            ->assertRedirect(route('phases.show', $phase))
            ->assertSessionHas('status');
    }
}
