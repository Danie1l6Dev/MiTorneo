<?php

namespace Tests\Feature\Tournaments;

use App\Enums\CompetitionPhaseType;
use App\Enums\MatchStatus;
use App\Models\Category;
use App\Models\CompetitionPhase;
use App\Models\Group;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A knockout-style phase (Knockout/Semifinal/Final) is built as a full
 * single-elimination bracket up front, not just its first round: later
 * rounds are pre-created with their two sides pending, and get resolved to
 * the actual winning teams automatically as earlier matches finish.
 */
class KnockoutBracketProgressionTest extends TestCase
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

    public function test_advancing_with_eight_qualifiers_builds_the_full_bracket_up_to_the_final(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['type' => CompetitionPhaseType::League]);
        $teams = Team::factory()->for($tournament)->for($category)->count(8)->create();

        foreach ($teams->chunk(2) as $pair) {
            $this->finishedMatch($phase, $pair->first(), $pair->last());
        }

        $this->actingAs($user)->post(route('phases.advance.store', $phase), [
            'name' => 'Cuartos',
            'type' => CompetitionPhaseType::Knockout->value,
            'qualifiers_per_table' => 8,
        ]);

        $newPhase = CompetitionPhase::where('name', 'Cuartos')->firstOrFail();
        $matches = $newPhase->matches()->get();

        // 4 quarterfinal matches (real teams) + 2 semifinal + 1 final (both pending).
        $this->assertCount(7, $matches);

        $quarters = $matches->where('round_number', 1);
        $this->assertCount(4, $quarters);
        $this->assertTrue($quarters->every(fn (TournamentMatch $m): bool => $m->home_team_id !== null && $m->away_team_id !== null));

        $semis = $matches->where('round_number', 2);
        $this->assertCount(2, $semis);
        $this->assertTrue($semis->every(fn (TournamentMatch $m): bool => $m->home_team_id === null && $m->away_team_id === null));

        $final = $matches->where('round_number', 3);
        $this->assertCount(1, $final);
        $this->assertTrue($final->every(fn (TournamentMatch $m): bool => $m->home_team_id === null && $m->away_team_id === null));
    }

    public function test_finishing_a_round_automatically_fills_in_the_next_round_and_eventually_the_final(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['type' => CompetitionPhaseType::League]);
        $teams = Team::factory()->for($tournament)->for($category)->count(4)->create();

        foreach ($teams->chunk(2) as $pair) {
            $this->finishedMatch($phase, $pair->first(), $pair->last());
        }

        $this->actingAs($user)->post(route('phases.advance.store', $phase), [
            'name' => 'Semifinales',
            'type' => CompetitionPhaseType::Knockout->value,
            'qualifiers_per_table' => 4,
        ]);

        $newPhase = CompetitionPhase::where('name', 'Semifinales')->firstOrFail();
        $semiMatches = $newPhase->matches()->where('round_number', 1)->orderBy('id')->get();
        $final = $newPhase->matches()->where('round_number', 2)->firstOrFail();

        $this->assertNull($final->fresh()->home_team_id);
        $this->assertNull($final->fresh()->away_team_id);

        // Finishing only the first semifinal fills in exactly one side of the final.
        $this->actingAs($user)->patch(route('matches.result.update', $semiMatches[0]), [
            'home_score' => 2,
            'away_score' => 1,
        ]);

        $final->refresh();
        $expectedFirstWinner = $semiMatches[0]->home_team_id;
        $this->assertSame($expectedFirstWinner, $final->home_team_id);
        $this->assertNull($final->away_team_id);

        // Finishing the second semifinal fills in the other side.
        $this->actingAs($user)->patch(route('matches.result.update', $semiMatches[1]), [
            'home_score' => 0,
            'away_score' => 3,
        ]);

        $final->refresh();
        $expectedSecondWinner = $semiMatches[1]->away_team_id;
        $this->assertSame($expectedSecondWinner, $final->away_team_id);

        // The final is now playable and its result no longer resolves anything further.
        $this->actingAs($user)
            ->patch(route('matches.result.update', $final), ['home_score' => 1, 'away_score' => 0])
            ->assertRedirect(route('matches.edit', $final));

        $this->assertSame(MatchStatus::Finished, $final->fresh()->status);
    }

    public function test_the_phase_page_shows_a_champion_card_once_the_final_is_finished(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['type' => CompetitionPhaseType::League]);
        $teams = Team::factory()->for($tournament)->for($category)->count(4)->create();

        foreach ($teams->chunk(2) as $pair) {
            $this->finishedMatch($phase, $pair->first(), $pair->last());
        }

        $this->actingAs($user)->post(route('phases.advance.store', $phase), [
            'name' => 'Semifinales',
            'type' => CompetitionPhaseType::Knockout->value,
            'qualifiers_per_table' => 4,
        ]);

        $newPhase = CompetitionPhase::where('name', 'Semifinales')->firstOrFail();
        $semiMatches = $newPhase->matches()->where('round_number', 1)->orderBy('id')->get();
        $final = $newPhase->matches()->where('round_number', 2)->firstOrFail();

        // Before the final is played, there's no champion card yet.
        $this->actingAs($user)
            ->get(route('phases.show', $newPhase))
            ->assertOk()
            ->assertDontSee('Campeón');

        $this->actingAs($user)->patch(route('matches.result.update', $semiMatches[0]), ['home_score' => 2, 'away_score' => 1]);
        $this->actingAs($user)->patch(route('matches.result.update', $semiMatches[1]), ['home_score' => 0, 'away_score' => 3]);
        $this->actingAs($user)->patch(route('matches.result.update', $final), ['home_score' => 2, 'away_score' => 0]);

        $champion = $final->fresh()->homeTeam;

        $this->actingAs($user)
            ->get(route('phases.show', $newPhase))
            ->assertOk()
            ->assertSee('Campeón')
            ->assertSee($champion->name);
    }

    public function test_a_pending_match_cannot_have_a_result_registered_before_both_teams_are_known(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['type' => CompetitionPhaseType::League]);
        $teams = Team::factory()->for($tournament)->for($category)->count(4)->create();

        foreach ($teams->chunk(2) as $pair) {
            $this->finishedMatch($phase, $pair->first(), $pair->last());
        }

        $this->actingAs($user)->post(route('phases.advance.store', $phase), [
            'name' => 'Semifinales',
            'type' => CompetitionPhaseType::Knockout->value,
            'qualifiers_per_table' => 4,
        ]);

        $newPhase = CompetitionPhase::where('name', 'Semifinales')->firstOrFail();
        $final = $newPhase->matches()->where('round_number', 2)->firstOrFail();

        $this->actingAs($user)
            ->patch(route('matches.result.update', $final), ['home_score' => 1, 'away_score' => 0])
            ->assertSessionHasErrors('home_score');

        $this->assertSame(MatchStatus::Scheduled, $final->fresh()->status);
    }

    public function test_a_knockout_match_cannot_end_in_a_draw(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->usingGroups()->create();
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['type' => CompetitionPhaseType::League]);
        $groupA = Group::factory()->for($tournament)->for($category)->create();
        $groupB = Group::factory()->for($tournament)->for($category)->create();
        $teamsA = Team::factory()->for($tournament)->for($category)->for($groupA)->count(2)->create();
        $teamsB = Team::factory()->for($tournament)->for($category)->for($groupB)->count(2)->create();

        $this->finishedMatch($phase, $teamsA[0], $teamsA[1], $groupA);
        $this->finishedMatch($phase, $teamsB[0], $teamsB[1], $groupB);

        $this->actingAs($user)->post(route('phases.advance.store', $phase), [
            'name' => 'Final',
            'type' => CompetitionPhaseType::Final->value,
        ]);

        $final = CompetitionPhase::where('name', 'Final')->firstOrFail();
        $match = $final->matches()->firstOrFail();

        $this->actingAs($user)
            ->patch(route('matches.result.update', $match), ['home_score' => 1, 'away_score' => 1])
            ->assertSessionHasErrors('home_score');

        $this->assertSame(MatchStatus::Scheduled, $match->fresh()->status);
    }

    public function test_a_league_match_can_still_end_in_a_draw(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['type' => CompetitionPhaseType::League]);
        $home = Team::factory()->for($tournament)->for($category)->create();
        $away = Team::factory()->for($tournament)->for($category)->create();

        $match = TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $tournament->id,
            'category_id' => $category->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'status' => MatchStatus::Scheduled,
        ]);

        $this->actingAs($user)
            ->patch(route('matches.result.update', $match), ['home_score' => 1, 'away_score' => 1])
            ->assertRedirect(route('matches.edit', $match));

        $this->assertSame(MatchStatus::Finished, $match->fresh()->status);
    }

    public function test_the_phase_page_shows_the_bracket_grouped_and_labeled_by_round(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['type' => CompetitionPhaseType::League]);
        $teams = Team::factory()->for($tournament)->for($category)->count(4)->create();

        foreach ($teams->chunk(2) as $pair) {
            $this->finishedMatch($phase, $pair->first(), $pair->last());
        }

        $this->actingAs($user)->post(route('phases.advance.store', $phase), [
            'name' => 'Semifinales',
            'type' => CompetitionPhaseType::Knockout->value,
            'qualifiers_per_table' => 4,
        ]);

        $newPhase = CompetitionPhase::where('name', 'Semifinales')->firstOrFail();

        $this->actingAs($user)
            ->get(route('phases.show', $newPhase))
            ->assertOk()
            ->assertSee('Semifinal')
            ->assertSee('Final')
            ->assertSee('Por definir');
    }
}
