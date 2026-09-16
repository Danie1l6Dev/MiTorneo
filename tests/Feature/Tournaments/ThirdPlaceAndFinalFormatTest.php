<?php

namespace Tests\Feature\Tournaments;

use App\Enums\CompetitionPhaseType;
use App\Enums\DrawMethod;
use App\Enums\MatchParticipantSourceType;
use App\Enums\MatchStatus;
use App\Enums\ScheduleFormat;
use App\Models\Category;
use App\Models\CompetitionPhase;
use App\Models\Group;
use App\Models\MatchParticipant;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * plays_third_place (an optional 3er/4to puesto match, always single-leg,
 * wired to "the loser of" each semifinal cross) and final_knockout_format
 * (an independent format override for the final cross only) -- both opted
 * into when a knockout-style phase is created, either directly (a
 * category's first phase) or via the advance-from-a-league flow.
 */
class ThirdPlaceAndFinalFormatTest extends TestCase
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

    public function test_a_category_can_start_a_knockout_with_a_third_place_match(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $teams = Team::factory()->for($tournament)->for($category)->count(4)->create();

        $this->actingAs($user)->post(route('categories.phases.store', $category), [
            'name' => 'Eliminatoria',
            'type' => CompetitionPhaseType::Knockout->value,
            'draw_method' => DrawMethod::Random->value,
            'plays_third_place' => '1',
        ]);

        $phase = CompetitionPhase::where('name', 'ELIMINATORIA')->firstOrFail();
        $this->assertTrue($phase->plays_third_place);

        $thirdPlaceMatch = TournamentMatch::where('competition_phase_id', $phase->id)->where('is_third_place', true)->firstOrFail();
        $this->assertNull($thirdPlaceMatch->home_team_id);
        $this->assertNull($thirdPlaceMatch->away_team_id);

        $semis = $phase->matches()->where('round_number', 1)->where('is_third_place', false)->orderBy('id')->get();
        $this->assertCount(2, $semis);

        $participants = MatchParticipant::where('match_id', $thirdPlaceMatch->id)->get();
        $this->assertCount(2, $participants);
        $this->assertTrue($participants->every(fn (MatchParticipant $p): bool => $p->type === MatchParticipantSourceType::MatchLoser));
        $this->assertSame($semis->pluck('id')->sort()->values()->all(), $participants->pluck('source_match_id')->sort()->values()->all());
    }

    public function test_finishing_both_semifinals_fills_the_third_place_match_with_the_losers(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        Team::factory()->for($tournament)->for($category)->count(4)->create();

        $this->actingAs($user)->post(route('categories.phases.store', $category), [
            'name' => 'Eliminatoria',
            'type' => CompetitionPhaseType::Knockout->value,
            'draw_method' => DrawMethod::Random->value,
            'plays_third_place' => '1',
        ]);

        $phase = CompetitionPhase::where('name', 'ELIMINATORIA')->firstOrFail();
        $semis = $phase->matches()->where('round_number', 1)->where('is_third_place', false)->orderBy('id')->get();
        $final = $phase->matches()->where('round_number', 2)->where('is_third_place', false)->firstOrFail();
        $thirdPlaceMatch = $phase->matches()->where('is_third_place', true)->firstOrFail();

        $this->actingAs($user)->patch(route('matches.result.update', $semis[0]), ['home_score' => 2, 'away_score' => 1]);
        $this->actingAs($user)->patch(route('matches.result.update', $semis[1]), ['home_score' => 0, 'away_score' => 3]);

        $final->refresh();
        $thirdPlaceMatch->refresh();

        $firstLoser = $semis[0]->home_team_id === $final->home_team_id ? $semis[0]->away_team_id : $semis[0]->home_team_id;
        $secondLoser = $semis[1]->away_team_id === $final->away_team_id ? $semis[1]->home_team_id : $semis[1]->away_team_id;

        $this->assertSame($firstLoser, $thirdPlaceMatch->home_team_id);
        $this->assertSame($secondLoser, $thirdPlaceMatch->away_team_id);

        // Neither semifinal's winner ended up on the third-place match.
        $this->assertNotSame($final->home_team_id, $thirdPlaceMatch->home_team_id);
        $this->assertNotSame($final->away_team_id, $thirdPlaceMatch->away_team_id);
    }

    public function test_third_place_match_is_never_counted_as_a_second_cross_of_the_final_round(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        Team::factory()->for($tournament)->for($category)->count(8)->create();

        $this->actingAs($user)->post(route('categories.phases.store', $category), [
            'name' => 'Eliminatoria',
            'type' => CompetitionPhaseType::Knockout->value,
            'draw_method' => DrawMethod::Random->value,
            'plays_third_place' => '1',
        ]);

        $phase = CompetitionPhase::where('name', 'ELIMINATORIA')->firstOrFail();

        $response = $this->actingAs($user)->get(route('phases.show', $phase));

        $response->assertOk();
        $bracketRounds = $response->viewData('bracketRounds');

        // 4 quarterfinal crosses, 2 semifinal, 1 final -- the third-place
        // match must never inflate the final round's cross count to 2
        // (which would otherwise mislabel it "Semifinal").
        $this->assertCount(3, $bracketRounds);
        $finalRound = end($bracketRounds);
        $this->assertSame('Final', $finalRound['label']);
        $this->assertCount(1, $finalRound['matches']);

        $this->assertNotNull($response->viewData('thirdPlaceMatch'));
    }

    public function test_plays_third_place_is_rejected_with_fewer_than_four_qualifiers(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        Team::factory()->for($tournament)->for($category)->count(2)->create();

        $response = $this->actingAs($user)->post(route('categories.phases.store', $category), [
            'name' => 'Eliminatoria',
            'type' => CompetitionPhaseType::Knockout->value,
            'draw_method' => DrawMethod::Random->value,
            'plays_third_place' => '1',
        ]);

        $response->assertSessionHasErrors('plays_third_place');
        $this->assertDatabaseMissing('competition_phases', ['name' => 'Eliminatoria']);
    }

    public function test_plays_third_place_is_rejected_via_the_advance_flow_with_fewer_than_four_qualifiers(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['type' => CompetitionPhaseType::League]);
        $teams = Team::factory()->for($tournament)->for($category)->count(4)->create();

        foreach ($teams->chunk(2) as $pair) {
            $this->finishedMatch($phase, $pair->first(), $pair->last());
        }

        $response = $this->actingAs($user)->post(route('phases.advance.store', $phase), [
            'name' => 'Semifinales',
            'type' => CompetitionPhaseType::Knockout->value,
            'draw_method' => DrawMethod::Random->value,
            'qualifiers_per_table' => 2,
            'plays_third_place' => '1',
        ]);

        $response->assertSessionHasErrors('plays_third_place');
        $this->assertDatabaseMissing('competition_phases', ['name' => 'Semifinales']);
    }

    public function test_the_final_can_use_a_different_format_than_the_rest_of_the_bracket(): void
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
            'draw_method' => DrawMethod::Random->value,
            'qualifiers_per_table' => 8,
            'knockout_format' => ScheduleFormat::SingleRound->value,
            'final_knockout_format' => ScheduleFormat::HomeAndAway->value,
        ]);

        $newPhase = CompetitionPhase::where('name', 'CUARTOS')->firstOrFail();
        $this->assertSame(ScheduleFormat::HomeAndAway, $newPhase->final_knockout_format);

        // Quarterfinals (round 1) and semifinals (round 2) are single matches.
        $this->assertSame(4, $newPhase->matches()->where('round_number', 1)->count());
        $this->assertSame(2, $newPhase->matches()->where('round_number', 2)->count());

        // The final (round 3) is two-legged: 2 matches, linked via first_leg_match_id.
        $finalMatches = $newPhase->matches()->where('round_number', 3)->get();
        $this->assertCount(2, $finalMatches);
        $this->assertTrue($finalMatches->contains(fn (TournamentMatch $m): bool => $m->first_leg_match_id !== null));
    }

    public function test_the_final_can_stay_single_match_while_the_rest_of_the_bracket_is_two_legged(): void
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
            'draw_method' => DrawMethod::Random->value,
            'qualifiers_per_table' => 4,
            'knockout_format' => ScheduleFormat::HomeAndAway->value,
            'final_knockout_format' => ScheduleFormat::SingleRound->value,
        ]);

        $newPhase = CompetitionPhase::where('name', 'SEMIFINALES')->firstOrFail();

        // Semifinals (round 1) are two-legged: 4 matches for 2 crosses.
        $this->assertSame(4, $newPhase->matches()->where('round_number', 1)->count());

        // The final (round 2) stayed a single match.
        $finalMatches = $newPhase->matches()->where('round_number', 2)->get();
        $this->assertCount(1, $finalMatches);
        $this->assertNull($finalMatches->first()->first_leg_match_id);
    }

    public function test_a_two_qualifier_bracket_still_applies_final_knockout_format_and_never_offers_third_place(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['type' => CompetitionPhaseType::League]);
        $teams = Team::factory()->for($tournament)->for($category)->count(2)->create();

        $this->finishedMatch($phase, $teams[0], $teams[1]);

        $this->actingAs($user)->post(route('phases.advance.store', $phase), [
            'name' => 'Final',
            'type' => CompetitionPhaseType::Final->value,
            'draw_method' => DrawMethod::Random->value,
            'knockout_format' => ScheduleFormat::SingleRound->value,
            'final_knockout_format' => ScheduleFormat::HomeAndAway->value,
        ]);

        $newPhase = CompetitionPhase::where('name', 'FINAL')->firstOrFail();

        // Round 1 IS the final here (only 2 qualifiers) -- it must still
        // honor final_knockout_format, not the general knockout_format.
        $matches = $newPhase->matches()->where('round_number', 1)->get();
        $this->assertCount(2, $matches);
        $this->assertTrue($matches->contains(fn (TournamentMatch $m): bool => $m->first_leg_match_id !== null));

        $this->assertSame(0, $newPhase->matches()->where('is_third_place', true)->count());
    }
}
