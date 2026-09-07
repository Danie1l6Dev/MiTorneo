<?php

namespace Tests\Feature\Services;

use App\Enums\CompetitionPhaseType;
use App\Enums\MatchEventType;
use App\Enums\MatchStatus;
use App\Enums\StatisticsPhaseScope;
use App\Models\Category;
use App\Models\CompetitionPhase;
use App\Models\Group;
use App\Models\MatchEvent;
use App\Models\Player;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Services\CompetitionStatisticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompetitionStatisticsServiceTest extends TestCase
{
    use RefreshDatabase;

    private CompetitionStatisticsService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new CompetitionStatisticsService;
    }

    private function makeCategory(bool $usesGroups = false): Category
    {
        $tournament = Tournament::factory()->create();

        return Category::factory()->for($tournament)->create(['uses_groups' => $usesGroups]);
    }

    private function makeTeam(Category $category, ?Group $group = null): Team
    {
        return Team::factory()->for($category)->for($category->tournament)->create([
            'group_id' => $group?->id,
        ]);
    }

    private function makePhase(Category $category, CompetitionPhaseType $type = CompetitionPhaseType::League): CompetitionPhase
    {
        return CompetitionPhase::factory()->for($category)->for($category->tournament)->create(['type' => $type]);
    }

    private function makeFinishedMatch(CompetitionPhase $phase, Team $home, Team $away, MatchStatus $status = MatchStatus::Finished): TournamentMatch
    {
        return TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $phase->tournament_id,
            'category_id' => $phase->category_id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'home_score' => 1,
            'away_score' => 0,
            'status' => $status,
        ]);
    }

    private function recordEvent(TournamentMatch $match, Player $player, MatchEventType $type): MatchEvent
    {
        return MatchEvent::factory()->create([
            'match_id' => $match->id,
            'team_id' => $player->team_id,
            'player_id' => $player->id,
            'type' => $type,
        ]);
    }

    // ── Goles ────────────────────────────────────────────────────────────

    public function test_a_player_with_several_goals_appears_with_the_correct_total(): void
    {
        $category = $this->makeCategory();
        $phase = $this->makePhase($category);
        $home = $this->makeTeam($category);
        $away = $this->makeTeam($category);
        $player = Player::factory()->for($home)->create();
        $match = $this->makeFinishedMatch($phase, $home, $away);

        $this->recordEvent($match, $player, MatchEventType::Goal);
        $this->recordEvent($match, $player, MatchEventType::Goal);
        $this->recordEvent($match, $player, MatchEventType::Goal);

        $rows = $this->service->leaderboard($category, MatchEventType::Goal, null, StatisticsPhaseScope::All);

        $this->assertCount(1, $rows);
        $this->assertSame($player->id, $rows[0]['player']->id);
        $this->assertSame(3, $rows[0]['count']);
        $this->assertSame(1, $rows[0]['rank']);
    }

    public function test_goals_accumulate_across_multiple_matches_for_the_same_player(): void
    {
        $category = $this->makeCategory();
        $phase = $this->makePhase($category);
        $home = $this->makeTeam($category);
        $away = $this->makeTeam($category);
        $player = Player::factory()->for($home)->create();

        $matchOne = $this->makeFinishedMatch($phase, $home, $away);
        $matchTwo = $this->makeFinishedMatch($phase, $home, $away);

        $this->recordEvent($matchOne, $player, MatchEventType::Goal);
        $this->recordEvent($matchOne, $player, MatchEventType::Goal);
        $this->recordEvent($matchTwo, $player, MatchEventType::Goal);

        $rows = $this->service->leaderboard($category, MatchEventType::Goal, null, StatisticsPhaseScope::All);

        $this->assertSame(3, $rows[0]['count']);
    }

    public function test_players_from_different_teams_appear_as_independent_rows(): void
    {
        $category = $this->makeCategory();
        $phase = $this->makePhase($category);
        $home = $this->makeTeam($category);
        $away = $this->makeTeam($category);
        $homePlayer = Player::factory()->for($home)->create();
        $awayPlayer = Player::factory()->for($away)->create();
        $match = $this->makeFinishedMatch($phase, $home, $away);

        $this->recordEvent($match, $homePlayer, MatchEventType::Goal);
        $this->recordEvent($match, $homePlayer, MatchEventType::Goal);
        $this->recordEvent($match, $awayPlayer, MatchEventType::Goal);

        $rows = $this->service->leaderboard($category, MatchEventType::Goal, null, StatisticsPhaseScope::All);

        $this->assertCount(2, $rows);
    }

    public function test_two_players_with_the_same_full_name_are_kept_as_separate_rows(): void
    {
        $category = $this->makeCategory();
        $phase = $this->makePhase($category);
        $home = $this->makeTeam($category);
        $away = $this->makeTeam($category);
        $playerOne = Player::factory()->for($home)->create(['full_name' => 'Carlos Gómez']);
        $playerTwo = Player::factory()->for($away)->create(['full_name' => 'Carlos Gómez']);
        $match = $this->makeFinishedMatch($phase, $home, $away);

        $this->recordEvent($match, $playerOne, MatchEventType::Goal);
        $this->recordEvent($match, $playerOne, MatchEventType::Goal);
        $this->recordEvent($match, $playerTwo, MatchEventType::Goal);

        $rows = $this->service->leaderboard($category, MatchEventType::Goal, null, StatisticsPhaseScope::All);

        $this->assertCount(2, $rows);
        $this->assertSame($playerOne->id, $rows[0]['player']->id);
        $this->assertSame(2, $rows[0]['count']);
        $this->assertSame($playerTwo->id, $rows[1]['player']->id);
        $this->assertSame(1, $rows[1]['count']);
    }

    public function test_the_ranking_is_ordered_by_count_descending(): void
    {
        $category = $this->makeCategory();
        $phase = $this->makePhase($category);
        $home = $this->makeTeam($category);
        $away = $this->makeTeam($category);
        $topScorer = Player::factory()->for($home)->create();
        $secondScorer = Player::factory()->for($away)->create();
        $match = $this->makeFinishedMatch($phase, $home, $away);

        $this->recordEvent($match, $secondScorer, MatchEventType::Goal);
        $this->recordEvent($match, $topScorer, MatchEventType::Goal);
        $this->recordEvent($match, $topScorer, MatchEventType::Goal);

        $rows = $this->service->leaderboard($category, MatchEventType::Goal, null, StatisticsPhaseScope::All);

        $this->assertSame($topScorer->id, $rows[0]['player']->id);
        $this->assertSame($secondScorer->id, $rows[1]['player']->id);
    }

    public function test_two_players_tied_on_count_break_the_tie_by_name_ascending(): void
    {
        $category = $this->makeCategory();
        $phase = $this->makePhase($category);
        $home = $this->makeTeam($category);
        $away = $this->makeTeam($category);
        $zed = Player::factory()->for($home)->create(['full_name' => 'Zed Torres']);
        $ana = Player::factory()->for($away)->create(['full_name' => 'Ana Ruiz']);
        $match = $this->makeFinishedMatch($phase, $home, $away);

        $this->recordEvent($match, $zed, MatchEventType::Goal);
        $this->recordEvent($match, $ana, MatchEventType::Goal);

        $rows = $this->service->leaderboard($category, MatchEventType::Goal, null, StatisticsPhaseScope::All);

        $this->assertSame($ana->id, $rows[0]['player']->id);
        $this->assertSame($zed->id, $rows[1]['player']->id);
    }

    // ── Asistencias ──────────────────────────────────────────────────────

    public function test_assists_are_calculated_independently_from_goals(): void
    {
        $category = $this->makeCategory();
        $phase = $this->makePhase($category);
        $home = $this->makeTeam($category);
        $away = $this->makeTeam($category);
        $player = Player::factory()->for($home)->create();
        $match = $this->makeFinishedMatch($phase, $home, $away);

        $this->recordEvent($match, $player, MatchEventType::Goal);
        $this->recordEvent($match, $player, MatchEventType::Assist);
        $this->recordEvent($match, $player, MatchEventType::Assist);

        $rows = $this->service->leaderboard($category, MatchEventType::Assist, null, StatisticsPhaseScope::All);

        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows[0]['count']);
    }

    public function test_assists_ranking_orders_by_count_descending(): void
    {
        $category = $this->makeCategory();
        $phase = $this->makePhase($category);
        $home = $this->makeTeam($category);
        $away = $this->makeTeam($category);
        $topAssister = Player::factory()->for($home)->create();
        $secondAssister = Player::factory()->for($away)->create();
        $match = $this->makeFinishedMatch($phase, $home, $away);

        $this->recordEvent($match, $secondAssister, MatchEventType::Assist);
        $this->recordEvent($match, $topAssister, MatchEventType::Assist);
        $this->recordEvent($match, $topAssister, MatchEventType::Assist);

        $rows = $this->service->leaderboard($category, MatchEventType::Assist, null, StatisticsPhaseScope::All);

        $this->assertSame($topAssister->id, $rows[0]['player']->id);
    }

    // ── Amarillas ────────────────────────────────────────────────────────

    public function test_yellow_cards_are_calculated_correctly(): void
    {
        $category = $this->makeCategory();
        $phase = $this->makePhase($category);
        $home = $this->makeTeam($category);
        $away = $this->makeTeam($category);
        $player = Player::factory()->for($home)->create();
        $match = $this->makeFinishedMatch($phase, $home, $away);

        $this->recordEvent($match, $player, MatchEventType::YellowCard);
        $this->recordEvent($match, $player, MatchEventType::YellowCard);

        $rows = $this->service->leaderboard($category, MatchEventType::YellowCard, null, StatisticsPhaseScope::All);

        $this->assertSame(2, $rows[0]['count']);
    }

    public function test_yellow_cards_ranking_orders_by_count_descending(): void
    {
        $category = $this->makeCategory();
        $phase = $this->makePhase($category);
        $home = $this->makeTeam($category);
        $away = $this->makeTeam($category);
        $mostCarded = Player::factory()->for($home)->create();
        $leastCarded = Player::factory()->for($away)->create();
        $match = $this->makeFinishedMatch($phase, $home, $away);

        $this->recordEvent($match, $leastCarded, MatchEventType::YellowCard);
        $this->recordEvent($match, $mostCarded, MatchEventType::YellowCard);
        $this->recordEvent($match, $mostCarded, MatchEventType::YellowCard);

        $rows = $this->service->leaderboard($category, MatchEventType::YellowCard, null, StatisticsPhaseScope::All);

        $this->assertSame($mostCarded->id, $rows[0]['player']->id);
    }

    // ── Rojas ────────────────────────────────────────────────────────────

    public function test_red_cards_are_calculated_correctly(): void
    {
        $category = $this->makeCategory();
        $phase = $this->makePhase($category);
        $home = $this->makeTeam($category);
        $away = $this->makeTeam($category);
        $player = Player::factory()->for($home)->create();
        $match = $this->makeFinishedMatch($phase, $home, $away);

        $this->recordEvent($match, $player, MatchEventType::RedCard);

        $rows = $this->service->leaderboard($category, MatchEventType::RedCard, null, StatisticsPhaseScope::All);

        $this->assertSame(1, $rows[0]['count']);
    }

    public function test_red_cards_ranking_orders_by_count_descending(): void
    {
        $category = $this->makeCategory();
        $phase = $this->makePhase($category);
        $home = $this->makeTeam($category);
        $away = $this->makeTeam($category);
        $mostExpelled = Player::factory()->for($home)->create();
        $leastExpelled = Player::factory()->for($away)->create();
        $matchOne = $this->makeFinishedMatch($phase, $home, $away);
        $matchTwo = $this->makeFinishedMatch($phase, $home, $away);

        $this->recordEvent($matchOne, $leastExpelled, MatchEventType::RedCard);
        $this->recordEvent($matchOne, $mostExpelled, MatchEventType::RedCard);
        $this->recordEvent($matchTwo, $mostExpelled, MatchEventType::RedCard);

        $rows = $this->service->leaderboard($category, MatchEventType::RedCard, null, StatisticsPhaseScope::All);

        $this->assertSame($mostExpelled->id, $rows[0]['player']->id);
    }

    // ── Grupos ───────────────────────────────────────────────────────────

    public function test_filtering_by_a_group_only_includes_players_from_that_groups_teams(): void
    {
        $category = $this->makeCategory(usesGroups: true);
        $groupA = Group::factory()->for($category)->for($category->tournament)->create();
        $groupB = Group::factory()->for($category)->for($category->tournament)->create();
        $phase = $this->makePhase($category);

        $teamA = $this->makeTeam($category, $groupA);
        $teamB = $this->makeTeam($category, $groupB);
        $playerA = Player::factory()->for($teamA)->create();
        $playerB = Player::factory()->for($teamB)->create();

        $match = $this->makeFinishedMatch($phase, $teamA, $teamB);
        $this->recordEvent($match, $playerA, MatchEventType::Goal);
        $this->recordEvent($match, $playerB, MatchEventType::Goal);

        $rows = $this->service->leaderboard($category, MatchEventType::Goal, $groupA, StatisticsPhaseScope::All);

        $this->assertCount(1, $rows);
        $this->assertSame($playerA->id, $rows[0]['player']->id);
    }

    public function test_filtering_by_the_other_group_excludes_the_first_groups_players(): void
    {
        $category = $this->makeCategory(usesGroups: true);
        $groupA = Group::factory()->for($category)->for($category->tournament)->create();
        $groupB = Group::factory()->for($category)->for($category->tournament)->create();
        $phase = $this->makePhase($category);

        $teamA = $this->makeTeam($category, $groupA);
        $teamB = $this->makeTeam($category, $groupB);
        $playerA = Player::factory()->for($teamA)->create();
        $playerB = Player::factory()->for($teamB)->create();

        $match = $this->makeFinishedMatch($phase, $teamA, $teamB);
        $this->recordEvent($match, $playerA, MatchEventType::Goal);
        $this->recordEvent($match, $playerB, MatchEventType::Goal);

        $rows = $this->service->leaderboard($category, MatchEventType::Goal, $groupB, StatisticsPhaseScope::All);

        $this->assertCount(1, $rows);
        $this->assertSame($playerB->id, $rows[0]['player']->id);
    }

    public function test_no_group_filter_combines_every_group_of_the_category(): void
    {
        $category = $this->makeCategory(usesGroups: true);
        $groupA = Group::factory()->for($category)->for($category->tournament)->create();
        $groupB = Group::factory()->for($category)->for($category->tournament)->create();
        $phase = $this->makePhase($category);

        $teamA = $this->makeTeam($category, $groupA);
        $teamB = $this->makeTeam($category, $groupB);
        $playerA = Player::factory()->for($teamA)->create();
        $playerB = Player::factory()->for($teamB)->create();

        $match = $this->makeFinishedMatch($phase, $teamA, $teamB);
        $this->recordEvent($match, $playerA, MatchEventType::Goal);
        $this->recordEvent($match, $playerB, MatchEventType::Goal);

        $rows = $this->service->leaderboard($category, MatchEventType::Goal, null, StatisticsPhaseScope::All);

        $this->assertCount(2, $rows);
    }

    public function test_a_category_without_groups_ignores_any_group_filter(): void
    {
        $category = $this->makeCategory(usesGroups: false);
        $phase = $this->makePhase($category);
        $home = $this->makeTeam($category);
        $away = $this->makeTeam($category);
        $player = Player::factory()->for($home)->create();
        $match = $this->makeFinishedMatch($phase, $home, $away);

        $this->recordEvent($match, $player, MatchEventType::Goal);

        $rows = $this->service->leaderboard($category, MatchEventType::Goal, null, StatisticsPhaseScope::All);

        $this->assertCount(1, $rows);
    }

    public function test_a_players_group_is_determined_by_the_teams_own_group_not_a_later_phases_roster(): void
    {
        // A phase can mix teams from two groups (e.g. a knockout bracket
        // drawn from both), but a player's stats must still count toward
        // their team's original category group, not whatever the phase
        // happened to put them in.
        $category = $this->makeCategory(usesGroups: true);
        $groupA = Group::factory()->for($category)->for($category->tournament)->create();
        $groupB = Group::factory()->for($category)->for($category->tournament)->create();

        $leaguePhase = $this->makePhase($category, CompetitionPhaseType::League);
        $knockoutPhase = $this->makePhase($category, CompetitionPhaseType::Knockout);

        $teamA = $this->makeTeam($category, $groupA);
        $teamB = $this->makeTeam($category, $groupB);
        $playerA = Player::factory()->for($teamA)->create();

        // teamA plays a cross-group knockout match, but still belongs to Group A.
        $match = $this->makeFinishedMatch($knockoutPhase, $teamA, $teamB);
        $this->recordEvent($match, $playerA, MatchEventType::Goal);

        $rows = $this->service->leaderboard($category, MatchEventType::Goal, $groupA, StatisticsPhaseScope::All);

        $this->assertCount(1, $rows);
        $this->assertSame($playerA->id, $rows[0]['player']->id);
    }

    // ── Fases ────────────────────────────────────────────────────────────

    public function test_league_only_scope_excludes_goals_from_a_semifinal_phase(): void
    {
        $category = $this->makeCategory();
        $leaguePhase = $this->makePhase($category, CompetitionPhaseType::League);
        $semifinalPhase = $this->makePhase($category, CompetitionPhaseType::Semifinal);

        $home = $this->makeTeam($category);
        $away = $this->makeTeam($category);
        $player = Player::factory()->for($home)->create();

        $leagueMatch = $this->makeFinishedMatch($leaguePhase, $home, $away);
        $semifinalMatch = $this->makeFinishedMatch($semifinalPhase, $home, $away);

        $this->recordEvent($leagueMatch, $player, MatchEventType::Goal);
        $this->recordEvent($leagueMatch, $player, MatchEventType::Goal);
        $this->recordEvent($semifinalMatch, $player, MatchEventType::Goal);

        $rows = $this->service->leaderboard($category, MatchEventType::Goal, null, StatisticsPhaseScope::League);

        $this->assertSame(2, $rows[0]['count']);
    }

    public function test_whole_competition_scope_sums_every_phase_of_the_category(): void
    {
        $category = $this->makeCategory();
        $leaguePhase = $this->makePhase($category, CompetitionPhaseType::League);
        $semifinalPhase = $this->makePhase($category, CompetitionPhaseType::Semifinal);
        $finalPhase = $this->makePhase($category, CompetitionPhaseType::Final);

        $home = $this->makeTeam($category);
        $away = $this->makeTeam($category);
        $player = Player::factory()->for($home)->create();

        $this->recordEvent($this->makeFinishedMatch($leaguePhase, $home, $away), $player, MatchEventType::Goal);
        $this->recordEvent($this->makeFinishedMatch($leaguePhase, $home, $away), $player, MatchEventType::Goal);
        $this->recordEvent($this->makeFinishedMatch($semifinalPhase, $home, $away), $player, MatchEventType::Goal);
        $this->recordEvent($this->makeFinishedMatch($finalPhase, $home, $away), $player, MatchEventType::Goal);

        $rows = $this->service->leaderboard($category, MatchEventType::Goal, null, StatisticsPhaseScope::All);

        $this->assertSame(4, $rows[0]['count']);
    }

    public function test_group_and_league_only_scope_combine(): void
    {
        $category = $this->makeCategory(usesGroups: true);
        $groupA = Group::factory()->for($category)->for($category->tournament)->create();
        $leaguePhase = $this->makePhase($category, CompetitionPhaseType::League);
        $semifinalPhase = $this->makePhase($category, CompetitionPhaseType::Semifinal);

        $teamA = $this->makeTeam($category, $groupA);
        $away = $this->makeTeam($category);
        $player = Player::factory()->for($teamA)->create();

        $this->recordEvent($this->makeFinishedMatch($leaguePhase, $teamA, $away), $player, MatchEventType::Goal);
        $this->recordEvent($this->makeFinishedMatch($semifinalPhase, $teamA, $away), $player, MatchEventType::Goal);

        $rows = $this->service->leaderboard($category, MatchEventType::Goal, $groupA, StatisticsPhaseScope::League);

        $this->assertSame(1, $rows[0]['count']);
    }

    public function test_group_and_whole_competition_scope_combine(): void
    {
        $category = $this->makeCategory(usesGroups: true);
        $groupA = Group::factory()->for($category)->for($category->tournament)->create();
        $leaguePhase = $this->makePhase($category, CompetitionPhaseType::League);
        $semifinalPhase = $this->makePhase($category, CompetitionPhaseType::Semifinal);

        $teamA = $this->makeTeam($category, $groupA);
        $away = $this->makeTeam($category);
        $player = Player::factory()->for($teamA)->create();

        $this->recordEvent($this->makeFinishedMatch($leaguePhase, $teamA, $away), $player, MatchEventType::Goal);
        $this->recordEvent($this->makeFinishedMatch($semifinalPhase, $teamA, $away), $player, MatchEventType::Goal);

        $rows = $this->service->leaderboard($category, MatchEventType::Goal, $groupA, StatisticsPhaseScope::All);

        $this->assertSame(2, $rows[0]['count']);
    }

    public function test_matches_that_are_not_finished_are_not_counted(): void
    {
        $category = $this->makeCategory();
        $phase = $this->makePhase($category);
        $home = $this->makeTeam($category);
        $away = $this->makeTeam($category);
        $player = Player::factory()->for($home)->create();

        $scheduledMatch = $this->makeFinishedMatch($phase, $home, $away, MatchStatus::Scheduled);
        $cancelledMatch = $this->makeFinishedMatch($phase, $home, $away, MatchStatus::Cancelled);

        $this->recordEvent($scheduledMatch, $player, MatchEventType::Goal);
        $this->recordEvent($cancelledMatch, $player, MatchEventType::Goal);

        $rows = $this->service->leaderboard($category, MatchEventType::Goal, null, StatisticsPhaseScope::All);

        $this->assertCount(0, $rows);
    }

    public function test_events_from_another_categorys_matches_are_never_included(): void
    {
        $categoryOne = $this->makeCategory();
        $categoryTwo = $this->makeCategory();

        $phaseOne = $this->makePhase($categoryOne);
        $phaseTwo = $this->makePhase($categoryTwo);

        $homeOne = $this->makeTeam($categoryOne);
        $awayOne = $this->makeTeam($categoryOne);
        $homeTwo = $this->makeTeam($categoryTwo);
        $awayTwo = $this->makeTeam($categoryTwo);

        $playerOne = Player::factory()->for($homeOne)->create();
        $playerTwo = Player::factory()->for($homeTwo)->create();

        $this->recordEvent($this->makeFinishedMatch($phaseOne, $homeOne, $awayOne), $playerOne, MatchEventType::Goal);
        $this->recordEvent($this->makeFinishedMatch($phaseTwo, $homeTwo, $awayTwo), $playerTwo, MatchEventType::Goal);

        $rows = $this->service->leaderboard($categoryOne, MatchEventType::Goal, null, StatisticsPhaseScope::All);

        $this->assertCount(1, $rows);
        $this->assertSame($playerOne->id, $rows[0]['player']->id);
    }
}
