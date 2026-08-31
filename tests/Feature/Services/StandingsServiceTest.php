<?php

namespace Tests\Feature\Services;

use App\Enums\MatchStatus;
use App\Models\Category;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Services\StandingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class StandingsServiceTest extends TestCase
{
    use RefreshDatabase;

    private StandingsService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new StandingsService;
    }

    /**
     * @return Collection<int, Team>
     */
    private function makeTeams(int $count): Collection
    {
        $tournament = Tournament::factory()->create();
        $category = Category::factory()->for($tournament)->create();

        return Team::factory()->for($tournament)->for($category)->count($count)->create();
    }

    private function finishedMatch(Team $home, Team $away, int $homeScore, int $awayScore): TournamentMatch
    {
        return TournamentMatch::make([
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'home_score' => $homeScore,
            'away_score' => $awayScore,
            'status' => MatchStatus::Finished,
        ]);
    }

    /**
     * @param  array<int, array{team: Team, played: int, won: int, drawn: int, lost: int, goals_for: int, goals_against: int, goal_difference: int, points: int}>  $rows
     * @return array<int, array{team: Team, played: int, won: int, drawn: int, lost: int, goals_for: int, goals_against: int, goal_difference: int, points: int}>
     */
    private function keyByTeamId(array $rows): array
    {
        $keyed = [];

        foreach ($rows as $row) {
            $keyed[$row['team']->id] = $row;
        }

        return $keyed;
    }

    public function test_a_win_awards_three_points_to_the_winner_and_none_to_the_loser(): void
    {
        $teams = $this->makeTeams(2);
        [$home, $away] = $teams->all();

        $rows = $this->keyByTeamId($this->service->calculate($teams, collect([
            $this->finishedMatch($home, $away, 2, 0),
        ])));

        $this->assertSame(3, $rows[$home->id]['points']);
        $this->assertSame(1, $rows[$home->id]['won']);
        $this->assertSame(0, $rows[$home->id]['drawn']);
        $this->assertSame(0, $rows[$home->id]['lost']);

        $this->assertSame(0, $rows[$away->id]['points']);
        $this->assertSame(0, $rows[$away->id]['won']);
        $this->assertSame(1, $rows[$away->id]['lost']);
    }

    public function test_a_draw_awards_one_point_to_each_team(): void
    {
        $teams = $this->makeTeams(2);
        [$home, $away] = $teams->all();

        $rows = $this->keyByTeamId($this->service->calculate($teams, collect([
            $this->finishedMatch($home, $away, 1, 1),
        ])));

        $this->assertSame(1, $rows[$home->id]['points']);
        $this->assertSame(1, $rows[$home->id]['drawn']);
        $this->assertSame(1, $rows[$away->id]['points']);
        $this->assertSame(1, $rows[$away->id]['drawn']);
    }

    public function test_a_loss_is_recorded_correctly_for_the_away_team(): void
    {
        $teams = $this->makeTeams(2);
        [$home, $away] = $teams->all();

        $rows = $this->keyByTeamId($this->service->calculate($teams, collect([
            $this->finishedMatch($home, $away, 0, 3),
        ])));

        $this->assertSame(3, $rows[$away->id]['points']);
        $this->assertSame(1, $rows[$away->id]['won']);
        $this->assertSame(0, $rows[$home->id]['points']);
        $this->assertSame(1, $rows[$home->id]['lost']);
    }

    public function test_goal_difference_and_goals_for_and_against_are_calculated(): void
    {
        $teams = $this->makeTeams(2);
        [$home, $away] = $teams->all();

        $rows = $this->keyByTeamId($this->service->calculate($teams, collect([
            $this->finishedMatch($home, $away, 3, 1),
        ])));

        $this->assertSame(3, $rows[$home->id]['goals_for']);
        $this->assertSame(1, $rows[$home->id]['goals_against']);
        $this->assertSame(2, $rows[$home->id]['goal_difference']);

        $this->assertSame(1, $rows[$away->id]['goals_for']);
        $this->assertSame(3, $rows[$away->id]['goals_against']);
        $this->assertSame(-2, $rows[$away->id]['goal_difference']);
    }

    public function test_changing_a_result_changes_the_standings(): void
    {
        $teams = $this->makeTeams(2);
        [$home, $away] = $teams->all();

        $before = $this->keyByTeamId($this->service->calculate($teams, collect([
            $this->finishedMatch($home, $away, 3, 1),
        ])));

        $this->assertSame(3, $before[$home->id]['points']);
        $this->assertSame(0, $before[$away->id]['points']);

        $after = $this->keyByTeamId($this->service->calculate($teams, collect([
            $this->finishedMatch($home, $away, 2, 2),
        ])));

        $this->assertSame(1, $after[$home->id]['points']);
        $this->assertSame(1, $after[$away->id]['points']);
        $this->assertSame(1, $after[$home->id]['drawn']);
    }

    public function test_multiple_matches_accumulate_for_the_same_team(): void
    {
        $teams = $this->makeTeams(3);
        [$a, $b, $c] = $teams->all();

        $rows = $this->keyByTeamId($this->service->calculate($teams, collect([
            $this->finishedMatch($a, $b, 2, 0),
            $this->finishedMatch($c, $a, 1, 1),
        ])));

        $this->assertSame(2, $rows[$a->id]['played']);
        $this->assertSame(4, $rows[$a->id]['points']);
        $this->assertSame(1, $rows[$a->id]['won']);
        $this->assertSame(1, $rows[$a->id]['drawn']);
        $this->assertSame(3, $rows[$a->id]['goals_for']);
        $this->assertSame(1, $rows[$a->id]['goals_against']);
    }

    public function test_matches_that_are_not_finished_do_not_affect_the_standings(): void
    {
        $teams = $this->makeTeams(2);
        [$home, $away] = $teams->all();

        $scheduledMatch = TournamentMatch::make([
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'home_score' => null,
            'away_score' => null,
            'status' => MatchStatus::Scheduled,
        ]);

        $rows = $this->keyByTeamId($this->service->calculate($teams, collect([$scheduledMatch])));

        $this->assertSame(0, $rows[$home->id]['played']);
        $this->assertSame(0, $rows[$home->id]['points']);
        $this->assertSame(0, $rows[$away->id]['played']);
        $this->assertSame(0, $rows[$away->id]['points']);
    }

    public function test_standings_are_ordered_by_points_then_goal_difference_then_goals_for(): void
    {
        $teams = $this->makeTeams(3);
        [$a, $b, $c] = $teams->all();

        // A: 1 win (3 pts, GD +3). B: 1 win (3 pts, GD +1). C: 2 losses (0 pts).
        $rows = $this->service->calculate($teams, collect([
            $this->finishedMatch($a, $c, 4, 1),
            $this->finishedMatch($b, $c, 2, 1),
        ]));

        $this->assertSame($a->id, $rows[0]['team']->id);
        $this->assertSame($b->id, $rows[1]['team']->id);
        $this->assertSame($c->id, $rows[2]['team']->id);
    }

    public function test_a_category_without_groups_calculates_a_single_table_from_its_teams(): void
    {
        $teams = $this->makeTeams(4);
        [$a, $b, $c, $d] = $teams->all();

        $rows = $this->service->calculate($teams, collect([
            $this->finishedMatch($a, $b, 1, 0),
            $this->finishedMatch($c, $d, 2, 2),
        ]));

        $this->assertCount(4, $rows);
    }
}
