<?php

namespace Tests\Feature\Tournaments;

use App\Enums\CompetitionPhaseType;
use App\Enums\DrawMethod;
use App\Enums\MatchStatus;
use App\Enums\ScheduleFormat;
use App\Models\Category;
use App\Models\CompetitionPhase;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Services\KnockoutBracketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Covers the organizer's ability to choose, per knockout-style phase,
 * whether each cross is a single match or two legs -- and the engine that
 * generates matches and resolves the cross winner for either choice.
 */
class TwoLeggedKnockoutTest extends TestCase
{
    use RefreshDatabase;

    private function finishedLeagueMatch(CompetitionPhase $phase, Team $home, Team $away): TournamentMatch
    {
        return TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $phase->tournament_id,
            'category_id' => $phase->category_id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'home_score' => 1,
            'away_score' => 0,
            'status' => MatchStatus::Finished,
        ]);
    }

    /**
     * @return array{user: User, phase: CompetitionPhase, teams: Collection<int, Team>}
     */
    private function leaguePhaseWithFinishedRound(int $teamCount): array
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['type' => CompetitionPhaseType::League]);
        $teams = Team::factory()->for($tournament)->for($category)->count($teamCount)->create();

        foreach ($teams->chunk(2) as $pair) {
            $this->finishedLeagueMatch($phase, $pair->first(), $pair->last());
        }

        return ['user' => $user, 'phase' => $phase, 'teams' => $teams];
    }

    public function test_a_single_match_format_generates_one_match_per_cross(): void
    {
        ['user' => $user, 'phase' => $phase] = $this->leaguePhaseWithFinishedRound(4);

        $this->actingAs($user)->post(route('phases.advance.store', $phase), [
            'name' => 'Semifinales',
            'type' => CompetitionPhaseType::Knockout->value,
            'draw_method' => DrawMethod::Random->value,
            'knockout_format' => ScheduleFormat::SingleRound->value,
            'qualifiers_per_table' => 4,
        ]);

        $newPhase = CompetitionPhase::where('name', 'Semifinales')->firstOrFail();
        $this->assertSame(ScheduleFormat::SingleRound, $newPhase->knockout_format);

        // 2 semifinals + 1 final, one match each -- exactly like before this
        // feature existed.
        $this->assertSame(3, $newPhase->matches()->count());
        $this->assertTrue($newPhase->matches()->whereNull('first_leg_match_id')->count() === 3);
    }

    public function test_a_home_and_away_format_generates_two_matches_per_cross(): void
    {
        ['user' => $user, 'phase' => $phase] = $this->leaguePhaseWithFinishedRound(4);

        $this->actingAs($user)->post(route('phases.advance.store', $phase), [
            'name' => 'Semifinales',
            'type' => CompetitionPhaseType::Knockout->value,
            'draw_method' => DrawMethod::Random->value,
            'knockout_format' => ScheduleFormat::HomeAndAway->value,
            'qualifiers_per_table' => 4,
        ]);

        $newPhase = CompetitionPhase::where('name', 'Semifinales')->firstOrFail();
        $this->assertSame(ScheduleFormat::HomeAndAway, $newPhase->knockout_format);

        // 2 semifinal crosses + 1 final cross, 2 matches each = 6 total.
        $this->assertSame(6, $newPhase->matches()->count());

        $semiFirstLegs = $newPhase->matches()->where('round_number', 1)->whereNull('first_leg_match_id')->get();
        $this->assertCount(2, $semiFirstLegs);

        foreach ($semiFirstLegs as $firstLeg) {
            $secondLeg = $newPhase->matches()->where('first_leg_match_id', $firstLeg->id)->firstOrFail();

            // Sides swap between legs.
            $this->assertSame($firstLeg->home_team_id, $secondLeg->away_team_id);
            $this->assertSame($firstLeg->away_team_id, $secondLeg->home_team_id);
        }

        $finalFirstLeg = $newPhase->matches()->where('round_number', 2)->whereNull('first_leg_match_id')->firstOrFail();
        $this->assertNotNull($newPhase->matches()->where('first_leg_match_id', $finalFirstLeg->id)->first());
    }

    public function test_the_desktop_bracket_offers_a_picker_modal_for_a_playable_two_legged_cross(): void
    {
        ['user' => $user, 'phase' => $phase] = $this->leaguePhaseWithFinishedRound(4);

        $this->actingAs($user)->post(route('phases.advance.store', $phase), [
            'name' => 'Semifinales',
            'type' => CompetitionPhaseType::Knockout->value,
            'draw_method' => DrawMethod::Random->value,
            'knockout_format' => ScheduleFormat::HomeAndAway->value,
            'qualifiers_per_table' => 4,
        ]);

        $newPhase = CompetitionPhase::where('name', 'Semifinales')->firstOrFail();

        // Both semifinal crosses are immediately playable (real teams
        // already known), so clicking one must offer a picker between its
        // ida and its vuelta -- the card itself only ever shows the vuelta.
        $semiFirstLegs = $newPhase->matches()->where('round_number', 1)->whereNull('first_leg_match_id')->get();
        $this->assertCount(2, $semiFirstLegs);

        $response = $this->actingAs($user)->get(route('phases.show', $newPhase));
        $response->assertOk();

        foreach ($semiFirstLegs as $firstLeg) {
            $secondLeg = $newPhase->matches()->where('first_leg_match_id', $firstLeg->id)->firstOrFail();

            $response->assertSee("cross-{$firstLeg->id}", false);
            $response->assertSee(route('matches.edit', $firstLeg), false);
            $response->assertSee(route('matches.edit', $secondLeg), false);
        }
    }

    public function test_a_pending_two_legged_cross_offers_no_picker_yet(): void
    {
        ['user' => $user, 'phase' => $phase] = $this->leaguePhaseWithFinishedRound(4);

        $this->actingAs($user)->post(route('phases.advance.store', $phase), [
            'name' => 'Semifinales',
            'type' => CompetitionPhaseType::Knockout->value,
            'draw_method' => DrawMethod::Random->value,
            'knockout_format' => ScheduleFormat::HomeAndAway->value,
            'qualifiers_per_table' => 4,
        ]);

        $newPhase = CompetitionPhase::where('name', 'Semifinales')->firstOrFail();
        $finalFirstLeg = $newPhase->matches()->where('round_number', 2)->whereNull('first_leg_match_id')->firstOrFail();

        // The final's teams aren't known yet, so there's nothing meaningful
        // to pick between -- same as a pending single-match cross, its card
        // stays a plain, non-interactive block with no picker at all.
        $response = $this->actingAs($user)->get(route('phases.show', $newPhase));
        $response->assertOk()->assertDontSee("cross-{$finalFirstLeg->id}", false);
    }

    public function test_a_single_match_cross_never_shows_a_picker_modal(): void
    {
        ['user' => $user, 'phase' => $phase] = $this->leaguePhaseWithFinishedRound(4);

        $this->actingAs($user)->post(route('phases.advance.store', $phase), [
            'name' => 'Semifinales',
            'type' => CompetitionPhaseType::Knockout->value,
            'draw_method' => DrawMethod::Random->value,
            'knockout_format' => ScheduleFormat::SingleRound->value,
            'qualifiers_per_table' => 4,
        ]);

        $newPhase = CompetitionPhase::where('name', 'Semifinales')->firstOrFail();
        $semis = $newPhase->matches()->where('round_number', 1)->get();

        $response = $this->actingAs($user)->get(route('phases.show', $newPhase));
        $response->assertOk();

        foreach ($semis as $semi) {
            $response->assertDontSee("cross-{$semi->id}", false);
            $response->assertSee(route('matches.edit', $semi), false);
        }
    }

    public function test_a_home_and_away_cross_is_decided_by_the_aggregate_score(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)
            ->create(['type' => CompetitionPhaseType::Knockout, 'knockout_format' => ScheduleFormat::HomeAndAway]);
        $teamA = Team::factory()->for($tournament)->for($category)->create();
        $teamB = Team::factory()->for($tournament)->for($category)->create();

        app(KnockoutBracketService::class)->generateBracket($phase, collect([$teamA, $teamB]));

        $firstLeg = $phase->matches()->whereNull('first_leg_match_id')->firstOrFail();
        $secondLeg = $phase->matches()->where('first_leg_match_id', $firstLeg->id)->firstOrFail();

        $this->assertSame($teamA->id, $firstLeg->home_team_id);
        $this->assertSame($teamB->id, $firstLeg->away_team_id);
        $this->assertSame($teamB->id, $secondLeg->home_team_id);
        $this->assertSame($teamA->id, $secondLeg->away_team_id);

        // First leg: A beats B 2-0 at home. A draw in the second leg is
        // perfectly valid -- it isn't decisive on its own.
        $this->actingAs($user)
            ->patch(route('matches.result.update', $firstLeg), ['home_score' => 2, 'away_score' => 0])
            ->assertRedirect(route('phases.show', $phase).'#cuadro');

        // Second leg: B beats A 1-0 at home. Aggregate is A 2 - B 1: A
        // advances even though it lost the second leg outright.
        $this->actingAs($user)
            ->patch(route('matches.result.update', $secondLeg), ['home_score' => 1, 'away_score' => 0])
            ->assertRedirect(route('phases.show', $phase).'#cuadro');

        $secondLeg->refresh();
        $this->assertSame($teamA->id, $secondLeg->tieWinnerTeamId());
    }

    public function test_the_decisive_leg_cannot_be_scored_before_the_first_leg_finishes(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)
            ->create(['type' => CompetitionPhaseType::Knockout, 'knockout_format' => ScheduleFormat::HomeAndAway]);
        $teamA = Team::factory()->for($tournament)->for($category)->create();
        $teamB = Team::factory()->for($tournament)->for($category)->create();

        app(KnockoutBracketService::class)->generateBracket($phase, collect([$teamA, $teamB]));

        $firstLeg = $phase->matches()->whereNull('first_leg_match_id')->firstOrFail();
        $secondLeg = $phase->matches()->where('first_leg_match_id', $firstLeg->id)->firstOrFail();

        $this->actingAs($user)
            ->patch(route('matches.result.update', $secondLeg), ['home_score' => 1, 'away_score' => 0])
            ->assertSessionHasErrors('home_score');

        $this->assertSame(MatchStatus::Scheduled, $secondLeg->fresh()->status);
    }

    public function test_the_first_leg_can_end_in_a_draw(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)
            ->create(['type' => CompetitionPhaseType::Knockout, 'knockout_format' => ScheduleFormat::HomeAndAway]);
        $teamA = Team::factory()->for($tournament)->for($category)->create();
        $teamB = Team::factory()->for($tournament)->for($category)->create();

        app(KnockoutBracketService::class)->generateBracket($phase, collect([$teamA, $teamB]));

        $firstLeg = $phase->matches()->whereNull('first_leg_match_id')->firstOrFail();

        $this->actingAs($user)
            ->patch(route('matches.result.update', $firstLeg), ['home_score' => 1, 'away_score' => 1])
            ->assertRedirect(route('phases.show', $phase).'#cuadro');

        $this->assertSame(MatchStatus::Finished, $firstLeg->fresh()->status);
    }

    public function test_a_level_aggregate_still_needs_extra_time_or_penalties_on_the_second_leg(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)
            ->create(['type' => CompetitionPhaseType::Knockout, 'knockout_format' => ScheduleFormat::HomeAndAway]);
        $teamA = Team::factory()->for($tournament)->for($category)->create();
        $teamB = Team::factory()->for($tournament)->for($category)->create();

        app(KnockoutBracketService::class)->generateBracket($phase, collect([$teamA, $teamB]));

        $firstLeg = $phase->matches()->whereNull('first_leg_match_id')->firstOrFail();
        $secondLeg = $phase->matches()->where('first_leg_match_id', $firstLeg->id)->firstOrFail();

        $this->actingAs($user)->patch(route('matches.result.update', $firstLeg), ['home_score' => 1, 'away_score' => 1]);

        // Second leg also 1-1: aggregate is level (2-2) and neither extra
        // time nor penalties were provided.
        $this->actingAs($user)
            ->patch(route('matches.result.update', $secondLeg), ['home_score' => 1, 'away_score' => 1])
            ->assertSessionHasErrors('home_score');

        $this->assertSame(MatchStatus::Scheduled, $secondLeg->fresh()->status);
    }

    public function test_a_level_aggregate_is_decided_by_penalties_on_the_second_leg_reusing_the_existing_tie_break(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)
            ->create(['type' => CompetitionPhaseType::Knockout, 'knockout_format' => ScheduleFormat::HomeAndAway]);
        $teamA = Team::factory()->for($tournament)->for($category)->create();
        $teamB = Team::factory()->for($tournament)->for($category)->create();

        app(KnockoutBracketService::class)->generateBracket($phase, collect([$teamA, $teamB]));

        $firstLeg = $phase->matches()->whereNull('first_leg_match_id')->firstOrFail();
        $secondLeg = $phase->matches()->where('first_leg_match_id', $firstLeg->id)->firstOrFail();

        $this->actingAs($user)->patch(route('matches.result.update', $firstLeg), ['home_score' => 1, 'away_score' => 1]);

        $this->actingAs($user)
            ->patch(route('matches.result.update', $secondLeg), [
                'home_score' => 2,
                'away_score' => 2,
                'home_penalty_score' => 4,
                'away_penalty_score' => 5,
            ])
            ->assertRedirect(route('phases.show', $phase).'#cuadro');

        $secondLeg->refresh();
        $this->assertSame(MatchStatus::Finished, $secondLeg->status);
        // Home in the second leg is teamB (sides swap); away is teamA. Away
        // won the shoot-out, so teamA is the one who advances.
        $this->assertSame($teamA->id, $secondLeg->tieWinnerTeamId());
    }

    public function test_finishing_a_two_legged_cross_propagates_the_winner_to_the_next_round(): void
    {
        ['user' => $user, 'phase' => $phase] = $this->leaguePhaseWithFinishedRound(4);

        $this->actingAs($user)->post(route('phases.advance.store', $phase), [
            'name' => 'Semifinales',
            'type' => CompetitionPhaseType::Knockout->value,
            'draw_method' => DrawMethod::Random->value,
            'knockout_format' => ScheduleFormat::HomeAndAway->value,
            'qualifiers_per_table' => 4,
        ]);

        $newPhase = CompetitionPhase::where('name', 'Semifinales')->firstOrFail();
        $semiFirstLegs = $newPhase->matches()->where('round_number', 1)->whereNull('first_leg_match_id')->orderBy('id')->get();
        $finalFirstLeg = $newPhase->matches()->where('round_number', 2)->whereNull('first_leg_match_id')->firstOrFail();

        $this->assertNull($finalFirstLeg->fresh()->home_team_id);
        $this->assertNull($finalFirstLeg->fresh()->away_team_id);

        $semiOne = $semiFirstLegs[0];
        $semiOneSecondLeg = $newPhase->matches()->where('first_leg_match_id', $semiOne->id)->firstOrFail();

        // Finishing only the first leg of semifinal 1 resolves nothing yet.
        $this->actingAs($user)->patch(route('matches.result.update', $semiOne), ['home_score' => 2, 'away_score' => 0]);
        $finalFirstLeg->refresh();
        $this->assertNull($finalFirstLeg->home_team_id);

        // Finishing its second (decisive) leg fills in one side of the final.
        $this->actingAs($user)->patch(route('matches.result.update', $semiOneSecondLeg), ['home_score' => 0, 'away_score' => 0]);

        $finalFirstLeg->refresh();
        $expectedWinner = $semiOne->home_team_id;
        $this->assertTrue($finalFirstLeg->home_team_id === $expectedWinner || $finalFirstLeg->away_team_id === $expectedWinner);

        // And that same winner is also wired into the final's second leg,
        // on the opposite side.
        $finalSecondLeg = $newPhase->matches()->where('first_leg_match_id', $finalFirstLeg->id)->firstOrFail();
        $this->assertTrue($finalSecondLeg->home_team_id === $expectedWinner || $finalSecondLeg->away_team_id === $expectedWinner);
    }

    public function test_a_two_legged_final_champion_is_derived_from_the_aggregate_score(): void
    {
        ['user' => $user, 'phase' => $phase] = $this->leaguePhaseWithFinishedRound(4);

        $this->actingAs($user)->post(route('phases.advance.store', $phase), [
            'name' => 'Semifinales',
            'type' => CompetitionPhaseType::Knockout->value,
            'draw_method' => DrawMethod::Random->value,
            'knockout_format' => ScheduleFormat::HomeAndAway->value,
            'qualifiers_per_table' => 4,
        ]);

        $newPhase = CompetitionPhase::where('name', 'Semifinales')->firstOrFail();
        $semis = $newPhase->matches()->where('round_number', 1)->whereNull('first_leg_match_id')->orderBy('id')->get();

        foreach ($semis as $semi) {
            $secondLeg = $newPhase->matches()->where('first_leg_match_id', $semi->id)->firstOrFail();
            $this->actingAs($user)->patch(route('matches.result.update', $semi), ['home_score' => 1, 'away_score' => 0]);
            $this->actingAs($user)->patch(route('matches.result.update', $secondLeg), ['home_score' => 0, 'away_score' => 0]);
        }

        $finalFirstLeg = $newPhase->matches()->where('round_number', 2)->whereNull('first_leg_match_id')->firstOrFail();
        $finalSecondLeg = $newPhase->matches()->where('first_leg_match_id', $finalFirstLeg->id)->firstOrFail();

        $this->actingAs($user)->patch(route('matches.result.update', $finalFirstLeg->fresh()), ['home_score' => 3, 'away_score' => 1]);
        $this->actingAs($user)->patch(route('matches.result.update', $finalSecondLeg->fresh()), ['home_score' => 0, 'away_score' => 0]);

        $finalSecondLeg->refresh();
        $championId = $finalSecondLeg->tieWinnerTeamId();
        $this->assertNotNull($championId);
        // The first leg's home team won it 3-1 and the second leg (sides
        // swapped) was a draw, so the aggregate winner is the first leg's
        // home team.
        $this->assertSame($finalFirstLeg->fresh()->home_team_id, $championId);

        $response = $this->actingAs($user)->get(route('phases.show', $newPhase));
        $championName = Team::findOrFail($championId)->name;

        $response->assertOk()->assertSee('Campeón')->assertSee($championName);
    }
}
