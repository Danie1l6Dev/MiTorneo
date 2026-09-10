<?php

namespace Tests\Feature\Services;

use App\Enums\DrawMethod;
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

    public function test_ties_are_broken_by_points_first(): void
    {
        $teams = $this->makeTeams(3);
        [$a, $b, $c] = $teams->all();

        // A wins big (5-0) but loses to B: 3 pts, GD +4, 5 goals for.
        // B wins twice narrowly (1-0 each): 6 pts, GD +2, 2 goals for.
        // B must rank first purely on points despite the worse GD/goals for.
        $rows = $this->service->calculate($teams, collect([
            $this->finishedMatch($a, $c, 5, 0),
            $this->finishedMatch($b, $c, 1, 0),
            $this->finishedMatch($a, $b, 0, 1),
        ]));

        $keyed = $this->keyByTeamId($rows);
        $this->assertSame(3, $keyed[$a->id]['points']);
        $this->assertSame(6, $keyed[$b->id]['points']);
        $this->assertGreaterThan($keyed[$b->id]['goal_difference'], $keyed[$a->id]['goal_difference']);

        $this->assertSame($b->id, $rows[0]['team']->id);
        $this->assertSame($a->id, $rows[1]['team']->id);
        $this->assertSame($c->id, $rows[2]['team']->id);
    }

    public function test_ties_on_points_are_broken_by_goal_difference(): void
    {
        $teams = $this->makeTeams(2);
        [$a, $b] = $teams->all();

        // Both have exactly 3 points (1 win each), but A's win is by a
        // bigger margin, so it must rank first purely on goal difference.
        $rows = $this->service->calculate($teams, collect([
            $this->finishedMatch($a, $b, 5, 0),
            $this->finishedMatch($b, $a, 1, 0),
        ]));

        $this->assertSame($a->id, $rows[0]['team']->id);
        $this->assertSame(3, $rows[0]['points']);
        $this->assertSame(3, $rows[1]['points']);
        $this->assertGreaterThan($rows[1]['goal_difference'], $rows[0]['goal_difference']);
    }

    public function test_ties_on_points_and_goal_difference_are_broken_by_goals_for(): void
    {
        $teamsThree = $this->makeTeams(3);
        [$x, $y, $z] = $teamsThree->all();

        // Every match is a high-scoring draw, so all three teams end up tied
        // on points (2, one draw each) and goal difference (0) -- only the
        // total goals scored (5, 4, 3 respectively) tells them apart.
        $rows = $this->service->calculate($teamsThree, collect([
            $this->finishedMatch($x, $z, 3, 3),
            $this->finishedMatch($x, $y, 1, 1),
            $this->finishedMatch($y, $z, 2, 2),
        ]));

        $keyed = $this->keyByTeamId($rows);
        $this->assertSame(2, $keyed[$x->id]['points']);
        $this->assertSame(2, $keyed[$y->id]['points']);
        $this->assertSame(2, $keyed[$z->id]['points']);
        $this->assertSame(0, $keyed[$x->id]['goal_difference']);
        $this->assertSame(0, $keyed[$y->id]['goal_difference']);
        $this->assertSame(0, $keyed[$z->id]['goal_difference']);

        $this->assertSame($z->id, $rows[0]['team']->id);
        $this->assertSame($x->id, $rows[1]['team']->id);
        $this->assertSame($y->id, $rows[2]['team']->id);
    }

    public function test_a_tie_between_exactly_two_teams_is_broken_by_the_head_to_head_result(): void
    {
        $teams = $this->makeTeams(3);
        [$a, $b, $c] = $teams->all();

        // B beats A 3-0, A beats C 3-2, C beats B 5-0: every team has
        // exactly 1 win and 1 loss (3 points each), but by design A and B
        // land on the exact same goal difference (-2) and goals for (3) --
        // only their own head-to-head match (B beat A) tells them apart. C
        // has a clearly better goal difference (+4) and ranks first on its
        // own, untouched by the tie-break between the other two.
        //
        // B is deliberately made the head-to-head winner even though A was
        // declared (and so stored/iterated) first: a buggy implementation
        // that fails to actually apply the tie-break (silently falling back
        // to input order) would wrongly rank A above B instead.
        $rows = $this->service->calculate($teams, collect([
            $this->finishedMatch($b, $a, 3, 0),
            $this->finishedMatch($a, $c, 3, 2),
            $this->finishedMatch($c, $b, 5, 0),
        ]));

        $keyed = $this->keyByTeamId($rows);
        $this->assertSame(3, $keyed[$a->id]['points']);
        $this->assertSame(3, $keyed[$b->id]['points']);
        $this->assertSame($keyed[$a->id]['goal_difference'], $keyed[$b->id]['goal_difference']);
        $this->assertSame($keyed[$a->id]['goals_for'], $keyed[$b->id]['goals_for']);

        $this->assertSame($c->id, $rows[0]['team']->id);
        $this->assertSame($b->id, $rows[1]['team']->id);
        $this->assertSame($a->id, $rows[2]['team']->id);
    }

    public function test_a_larger_tied_cluster_is_resolved_by_a_mini_table_among_just_those_teams(): void
    {
        $teams = $this->makeTeams(3);
        [$a, $b, $c] = $teams->all();

        // All three are tied on points (3), goal difference (0) and goals
        // for (1) after a perfect round-robin cycle (A beats B, B beats C, C
        // beats A, each 1-0). The head-to-head mini-table among the three of
        // them is itself perfectly cyclical, so none of them can be
        // separated by it either -- they must simply keep their original
        // relative order rather than the tie-break looping or crashing.
        $rows = $this->service->calculate($teams, collect([
            $this->finishedMatch($a, $b, 1, 0),
            $this->finishedMatch($b, $c, 1, 0),
            $this->finishedMatch($c, $a, 1, 0),
        ]));

        $keyed = $this->keyByTeamId($rows);
        $this->assertSame(3, $keyed[$a->id]['points']);
        $this->assertSame(3, $keyed[$b->id]['points']);
        $this->assertSame(3, $keyed[$c->id]['points']);
        $this->assertCount(3, $rows);
        $this->assertEqualsCanonicalizing([$a->id, $b->id, $c->id], collect($rows)->pluck('team.id')->all());
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

    /**
     * @param  Collection<int, Team>  $teams  already in rank order, best first
     * @return array<int, array{team: Team}>
     */
    private function ranked(Collection $teams): array
    {
        return $teams->map(fn (Team $team): array => ['team' => $team])->all();
    }

    public function test_seeded_draw_from_a_single_table_pairs_the_best_against_the_worst(): void
    {
        $teams = $this->makeTeams(4);
        [$first, $second, $third, $fourth] = $teams->all();

        $tables = [['label' => 'Liga', 'rows' => $this->ranked($teams)]];

        $ordered = $this->service->seedQualifiers($tables, 4, DrawMethod::Seeded);

        $this->assertSame([$first->id, $fourth->id, $second->id, $third->id], $ordered->pluck('id')->all());
    }

    public function test_seeded_draw_from_two_tables_crosses_the_best_of_one_with_the_worst_of_the_other(): void
    {
        $groupA = $this->makeTeams(2);
        $groupB = $this->makeTeams(2);
        [$a1, $a2] = $groupA->all();
        [$b1, $b2] = $groupB->all();

        $tables = [
            ['label' => 'Grupo A', 'rows' => $this->ranked($groupA)],
            ['label' => 'Grupo B', 'rows' => $this->ranked($groupB)],
        ];

        $ordered = $this->service->seedQualifiers($tables, 2, DrawMethod::Seeded);

        // A's best vs B's worst, then A's worst vs B's best.
        $this->assertSame([$a1->id, $b2->id, $a2->id, $b1->id], $ordered->pluck('id')->all());
    }

    public function test_seeded_draw_only_takes_the_qualifying_rows_per_table(): void
    {
        $teams = $this->makeTeams(3);
        [$first, $second, $third] = $teams->all();

        $tables = [['label' => 'Liga', 'rows' => $this->ranked($teams)]];

        $ordered = $this->service->seedQualifiers($tables, 2, DrawMethod::Seeded);

        // Only the top 2 qualify: the 3rd-place team must not appear at all.
        $this->assertSame([$first->id, $second->id], $ordered->pluck('id')->all());
        $this->assertNotContains($third->id, $ordered->pluck('id')->all());
    }

    public function test_random_draw_includes_every_qualifier_exactly_once(): void
    {
        $teams = $this->makeTeams(4);

        $tables = [['label' => 'Liga', 'rows' => $this->ranked($teams)]];

        $ordered = $this->service->seedQualifiers($tables, 4, DrawMethod::Random);

        $this->assertEqualsCanonicalizing($teams->pluck('id')->all(), $ordered->pluck('id')->all());
    }
}
