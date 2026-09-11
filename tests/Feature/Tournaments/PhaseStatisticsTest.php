<?php

namespace Tests\Feature\Tournaments;

use App\Enums\CompetitionPhaseType;
use App\Enums\MatchEventType;
use App\Enums\MatchStatus;
use App\Models\Category;
use App\Models\CompetitionPhase;
use App\Models\Group;
use App\Models\MatchEvent;
use App\Models\Player;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseStatisticsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: CompetitionPhase, 2: Player}
     */
    private function makeLeaguePhaseWithAGoal(): array
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create();
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['type' => CompetitionPhaseType::League]);
        $home = Team::factory()->for($tournament)->for($category)->create();
        $away = Team::factory()->for($tournament)->for($category)->create();
        $player = Player::factory()->for($home)->create(['full_name' => 'Carlos Gómez']);

        $match = TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $tournament->id,
            'category_id' => $category->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'status' => MatchStatus::Finished,
        ]);

        MatchEvent::factory()->create([
            'match_id' => $match->id,
            'team_id' => $home->id,
            'player_id' => $player->id,
            'type' => MatchEventType::Goal,
        ]);

        return [$user, $phase, $player];
    }

    public function test_the_league_phase_page_shows_the_goalscorer_leaderboard(): void
    {
        [$user, $phase] = $this->makeLeaguePhaseWithAGoal();

        $this->actingAs($user)
            ->get(route('phases.show', $phase).'?view=goal')
            ->assertOk()
            ->assertSee('Carlos Gómez')
            ->assertSee(MatchEventType::Goal->leaderboardTitle());
    }

    public function test_the_assist_leaderboard_view_does_not_show_a_player_who_only_scored(): void
    {
        [$user, $phase, $player] = $this->makeLeaguePhaseWithAGoal();

        // Every leaderboard/group/scope combination is now preloaded on the
        // same page (so nothing ever reloads -- see
        // PhaseBoardService::statisticsPanels()), so Carlos Gómez DOES
        // appear somewhere in the raw HTML (the goal panel, just not the
        // visible one) -- a page-wide assertDontSee would wrongly fail.
        // Assert against the assist panel's own rows (view data) instead of
        // scraping rendered text.
        $this->actingAs($user)
            ->get(route('phases.show', $phase).'?view=assist')
            ->assertOk()
            ->assertViewHas(
                'statistics',
                fn (array $statistics): bool => collect($statistics['panels']['assist']['league']['all'])
                    ->doesntContain(fn (array $row): bool => $row['player']->is($player))
            );
    }

    public function test_a_knockout_phase_page_does_not_offer_the_statistics_tabs(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create();
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['type' => CompetitionPhaseType::Knockout]);

        $this->actingAs($user)
            ->get(route('phases.show', $phase).'?view=goal')
            ->assertOk()
            ->assertDontSee(MatchEventType::Goal->leaderboardTitle());
    }

    public function test_an_invalid_view_query_param_falls_back_to_the_default_page_without_erroring(): void
    {
        [$user, $phase] = $this->makeLeaguePhaseWithAGoal();

        $this->actingAs($user)
            ->get(route('phases.show', $phase).'?view=not-a-real-stat')
            ->assertOk();
    }

    public function test_an_invalid_phase_scope_query_param_falls_back_to_league_only_without_erroring(): void
    {
        [$user, $phase] = $this->makeLeaguePhaseWithAGoal();

        $this->actingAs($user)
            ->get(route('phases.show', $phase).'?view=goal&phase=not-a-real-scope')
            ->assertOk()
            ->assertSee('Carlos Gómez');
    }

    // ── Seguridad ────────────────────────────────────────────────────────

    public function test_a_user_cannot_view_another_users_phase_statistics(): void
    {
        [, $phase] = $this->makeLeaguePhaseWithAGoal();
        $otherUser = User::factory()->create();

        $this->actingAs($otherUser)
            ->get(route('phases.show', $phase).'?view=goal')
            ->assertForbidden();
    }

    public function test_a_group_id_belonging_to_another_category_is_silently_ignored(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();

        $categoryOne = Category::factory()->for($tournament)->usingGroups()->create();
        $groupOne = Group::factory()->for($tournament)->for($categoryOne)->create();
        $teamOne = Team::factory()->for($tournament)->for($categoryOne)->create(['group_id' => $groupOne->id]);
        $playerOne = Player::factory()->for($teamOne)->create(['full_name' => 'Jugador Uno']);
        $phaseOne = CompetitionPhase::factory()->for($tournament)->for($categoryOne)->create(['type' => CompetitionPhaseType::League]);
        $awayOne = Team::factory()->for($tournament)->for($categoryOne)->create();

        $matchOne = TournamentMatch::factory()->for($phaseOne)->create([
            'tournament_id' => $tournament->id,
            'category_id' => $categoryOne->id,
            'home_team_id' => $teamOne->id,
            'away_team_id' => $awayOne->id,
            'status' => MatchStatus::Finished,
        ]);

        MatchEvent::factory()->create([
            'match_id' => $matchOne->id,
            'team_id' => $teamOne->id,
            'player_id' => $playerOne->id,
            'type' => MatchEventType::Goal,
        ]);

        // A group that belongs to a *different* category.
        $categoryTwo = Category::factory()->for($tournament)->usingGroups()->create();
        $foreignGroup = Group::factory()->for($tournament)->for($categoryTwo)->create();

        // Requesting phaseOne's stats filtered by categoryTwo's group id must
        // not blow up, and must not filter as if that group belonged here --
        // it falls back to "no group filter" (every group of categoryOne),
        // so categoryOne's own player still shows.
        $this->actingAs($user)
            ->get(route('phases.show', $phaseOne)."?view=goal&group={$foreignGroup->id}")
            ->assertOk()
            ->assertSee('Jugador Uno');
    }

    public function test_only_finished_matches_are_ever_counted(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create();
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['type' => CompetitionPhaseType::League]);
        $home = Team::factory()->for($tournament)->for($category)->create();
        $away = Team::factory()->for($tournament)->for($category)->create();
        $player = Player::factory()->for($home)->create(['full_name' => 'Nunca Cuenta']);

        $cancelledMatch = TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $tournament->id,
            'category_id' => $category->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'status' => MatchStatus::Cancelled,
        ]);

        MatchEvent::factory()->create([
            'match_id' => $cancelledMatch->id,
            'team_id' => $home->id,
            'player_id' => $player->id,
            'type' => MatchEventType::Goal,
        ]);

        $this->actingAs($user)
            ->get(route('phases.show', $phase).'?view=goal')
            ->assertOk()
            ->assertDontSee('Nunca Cuenta');
    }

    public function test_league_only_scope_excludes_a_semifinal_phases_events_from_the_page(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create();
        $leaguePhase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['type' => CompetitionPhaseType::League]);
        $semifinal = CompetitionPhase::factory()->for($tournament)->for($category)->create(['type' => CompetitionPhaseType::Semifinal]);
        $home = Team::factory()->for($tournament)->for($category)->create();
        $away = Team::factory()->for($tournament)->for($category)->create();
        $player = Player::factory()->for($home)->create(['full_name' => 'Solo Semifinal']);

        $match = TournamentMatch::factory()->for($semifinal)->create([
            'tournament_id' => $tournament->id,
            'category_id' => $category->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'status' => MatchStatus::Finished,
        ]);

        MatchEvent::factory()->create([
            'match_id' => $match->id,
            'team_id' => $home->id,
            'player_id' => $player->id,
            'type' => MatchEventType::Goal,
        ]);

        // The tab bar (and so this "?view=" statistics section) only lives on
        // the league phase's own page -- that's the page being requested
        // here, even though the goal in question was scored in the semifinal.
        // Both phase-scope panels are now preloaded together (see
        // PhaseBoardService::statisticsPanels()), so "Solo Semifinal" DOES
        // appear somewhere in the raw HTML (the "toda la competición" panel)
        // regardless of which one was requested -- assert against the
        // league-scope panel's own view data instead of a page-wide
        // assertDontSee.
        $this->actingAs($user)
            ->get(route('phases.show', $leaguePhase).'?view=goal&phase=league')
            ->assertOk()
            ->assertViewHas(
                'statistics',
                fn (array $statistics): bool => collect($statistics['panels']['goal']['league']['all'])
                    ->doesntContain(fn (array $row): bool => $row['player']->is($player))
            );

        $this->actingAs($user)
            ->get(route('phases.show', $leaguePhase).'?view=goal&phase=all')
            ->assertOk()
            ->assertSee('Solo Semifinal');
    }
}
