<?php

namespace Tests\Feature\Tournaments;

use App\Enums\CompetitionPhaseType;
use App\Enums\MatchStatus;
use App\Models\Category;
use App\Models\CompetitionPhase;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Services\TeamExpulsionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseCutTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: CompetitionPhase, 2: array<string, TournamentMatch>}
     */
    private function leagueWithOneFinishedAndOnePendingMatch(): array
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['type' => CompetitionPhaseType::League, 'order' => 0]);
        $teams = Team::factory()->for($tournament)->for($category)->count(4)->create();

        $make = fn (Team $home, Team $away, MatchStatus $status): TournamentMatch => TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $tournament->id,
            'category_id' => $category->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'home_score' => $status === MatchStatus::Finished ? 2 : null,
            'away_score' => $status === MatchStatus::Finished ? 0 : null,
            'status' => $status,
        ]);

        return [$user, $phase, [
            'finished' => $make($teams[0], $teams[1], MatchStatus::Finished),
            'pending' => $make($teams[2], $teams[3], MatchStatus::Scheduled),
            'postponed' => $make($teams[0], $teams[2], MatchStatus::Postponed),
        ]];
    }

    public function test_a_league_with_pending_matches_cannot_be_advanced_until_it_is_cut(): void
    {
        [$user, $phase] = $this->leagueWithOneFinishedAndOnePendingMatch();

        $this->assertFalse($phase->fresh()->load('matches')->allMatchesFinished());

        $this->actingAs($user)->get(route('phases.advance.create', $phase))
            ->assertRedirect(route('phases.show', $phase));
    }

    public function test_cutting_a_league_cancels_its_unplayed_matches_and_unlocks_advancing(): void
    {
        [$user, $phase, $matches] = $this->leagueWithOneFinishedAndOnePendingMatch();

        $this->actingAs($user)->post(route('phases.cut.store', $phase))
            ->assertRedirect(route('phases.show', $phase))
            ->assertSessionHas('status');

        $this->assertSame(MatchStatus::Finished, $matches['finished']->fresh()->status);
        $this->assertFalse($matches['finished']->fresh()->cut_off);

        foreach (['pending', 'postponed'] as $key) {
            $this->assertSame(MatchStatus::Cancelled, $matches[$key]->fresh()->status);
            $this->assertTrue($matches[$key]->fresh()->cut_off);
        }

        $this->assertTrue($phase->fresh()->load('matches')->allMatchesFinished());

        $this->actingAs($user)->get(route('phases.advance.create', $phase))->assertOk();
    }

    public function test_a_cut_can_be_undone_while_nothing_was_built_from_the_league(): void
    {
        [$user, $phase, $matches] = $this->leagueWithOneFinishedAndOnePendingMatch();
        $this->actingAs($user)->post(route('phases.cut.store', $phase));

        // A match cancelled for another reason (not by the cut) stays as is.
        $other = TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $phase->tournament_id,
            'category_id' => $phase->category_id,
            'status' => MatchStatus::Cancelled,
        ]);

        $this->actingAs($user)->delete(route('phases.cut.destroy', $phase))
            ->assertRedirect(route('phases.show', $phase));

        $this->assertSame(MatchStatus::Scheduled, $matches['pending']->fresh()->status);
        $this->assertFalse($matches['pending']->fresh()->cut_off);
        $this->assertSame(MatchStatus::Cancelled, $other->fresh()->status);
    }

    public function test_a_cut_cannot_be_undone_once_a_next_phase_exists(): void
    {
        [$user, $phase, $matches] = $this->leagueWithOneFinishedAndOnePendingMatch();
        $this->actingAs($user)->post(route('phases.cut.store', $phase));

        CompetitionPhase::factory()->for($phase->tournament)->for($phase->category)->create(['type' => CompetitionPhaseType::Knockout, 'order' => 1]);

        $this->actingAs($user)->delete(route('phases.cut.destroy', $phase))->assertSessionHas('error');

        $this->assertSame(MatchStatus::Cancelled, $matches['pending']->fresh()->status);
    }

    public function test_only_the_owner_can_cut_a_league(): void
    {
        [, $phase, $matches] = $this->leagueWithOneFinishedAndOnePendingMatch();

        $this->actingAs(User::factory()->create())->post(route('phases.cut.store', $phase))->assertForbidden();

        $this->assertSame(MatchStatus::Scheduled, $matches['pending']->fresh()->status);
    }

    public function test_the_phase_page_offers_the_cut_only_while_matches_are_pending(): void
    {
        [$user, $phase] = $this->leagueWithOneFinishedAndOnePendingMatch();

        $this->actingAs($user)->get(route('phases.show', $phase))->assertSee('Recortar liga');

        $this->actingAs($user)->post(route('phases.cut.store', $phase));

        $this->actingAs($user)->get(route('phases.show', $phase))
            ->assertDontSee('Recortar liga')
            ->assertSee('Deshacer recorte');
    }

    public function test_an_expelled_team_is_left_out_of_the_played_matches_comparison(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['type' => CompetitionPhaseType::League, 'order' => 0]);
        [$a, $b, $c, $expelled] = Team::factory()->for($tournament)->for($category)->count(4)->create()->all();

        app(TeamExpulsionService::class)->expel($expelled, $tournament, null);

        $make = fn (Team $home, Team $away, MatchStatus $status): TournamentMatch => TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $tournament->id,
            'category_id' => $category->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'home_score' => $status === MatchStatus::Finished ? 1 : null,
            'away_score' => $status === MatchStatus::Finished ? 0 : null,
            'status' => $status,
        ]);

        $make($a, $b, MatchStatus::Finished);
        $make($b, $c, MatchStatus::Finished);
        $make($c, $a, MatchStatus::Finished);
        $make($a, $b, MatchStatus::Scheduled);

        // The expelled team played nothing, the rest 2 each: no uneven warning.
        $this->actingAs($user)->get(route('phases.show', $phase))
            ->assertSee('2 partidos jugados por equipo')
            ->assertDontSee('no jugaron la misma cantidad');
    }
}
