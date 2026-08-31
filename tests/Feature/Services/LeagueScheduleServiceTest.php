<?php

namespace Tests\Feature\Services;

use App\Enums\ScheduleFormat;
use App\Models\Category;
use App\Models\Team;
use App\Models\Tournament;
use App\Services\LeagueScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class LeagueScheduleServiceTest extends TestCase
{
    use RefreshDatabase;

    private LeagueScheduleService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new LeagueScheduleService;
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

    /**
     * @param  array<int, array{fixtures: array<int, array{home_team_id: int, away_team_id: int}>}>  $rounds
     * @return Collection<int, array{home_team_id: int, away_team_id: int}>
     */
    private function allFixtures(array $rounds): Collection
    {
        return collect($rounds)->flatMap(fn (array $round) => $round['fixtures']);
    }

    /**
     * @param  Collection<int, int>  $teamIds
     * @return array<int, string>
     */
    private function allPossiblePairs(Collection $teamIds): array
    {
        $ids = $teamIds->values()->all();
        $pairs = [];

        for ($i = 0; $i < count($ids); $i++) {
            for ($j = $i + 1; $j < count($ids); $j++) {
                $pairs[] = collect([$ids[$i], $ids[$j]])->sort()->implode('-');
            }
        }

        return $pairs;
    }

    public function test_single_round_with_four_teams_generates_three_rounds(): void
    {
        $rounds = $this->service->generate($this->makeTeams(4), ScheduleFormat::SingleRound);

        $this->assertCount(3, $rounds);
    }

    public function test_single_round_with_four_teams_generates_six_matches(): void
    {
        $rounds = $this->service->generate($this->makeTeams(4), ScheduleFormat::SingleRound);

        $this->assertCount(6, $this->allFixtures($rounds));
    }

    public function test_single_round_with_five_teams_generates_five_rounds(): void
    {
        $rounds = $this->service->generate($this->makeTeams(5), ScheduleFormat::SingleRound);

        $this->assertCount(5, $rounds);
    }

    public function test_single_round_with_five_teams_generates_ten_matches(): void
    {
        $rounds = $this->service->generate($this->makeTeams(5), ScheduleFormat::SingleRound);

        $this->assertCount(10, $this->allFixtures($rounds));
    }

    public function test_single_round_with_five_teams_has_exactly_one_resting_team_per_round(): void
    {
        $rounds = $this->service->generate($this->makeTeams(5), ScheduleFormat::SingleRound);

        foreach ($rounds as $round) {
            $this->assertNotNull($round['resting_team_id']);
        }
    }

    public function test_single_round_never_pairs_a_team_against_itself(): void
    {
        $rounds = $this->service->generate($this->makeTeams(5), ScheduleFormat::SingleRound);

        foreach ($this->allFixtures($rounds) as $fixture) {
            $this->assertNotSame($fixture['home_team_id'], $fixture['away_team_id']);
        }
    }

    public function test_single_round_never_repeats_a_pairing(): void
    {
        $teams = $this->makeTeams(5);
        $rounds = $this->service->generate($teams, ScheduleFormat::SingleRound);

        $pairs = $this->allFixtures($rounds)->map(
            fn (array $fixture) => collect([$fixture['home_team_id'], $fixture['away_team_id']])->sort()->implode('-')
        );

        $this->assertCount($pairs->count(), $pairs->unique());
    }

    public function test_single_round_makes_every_team_face_every_rival_exactly_once(): void
    {
        $teams = $this->makeTeams(5);
        $rounds = $this->service->generate($teams, ScheduleFormat::SingleRound);

        $pairs = $this->allFixtures($rounds)->map(
            fn (array $fixture) => collect([$fixture['home_team_id'], $fixture['away_team_id']])->sort()->implode('-')
        );

        $expectedPairs = $this->allPossiblePairs($teams->pluck('id'));

        $this->assertEqualsCanonicalizing($expectedPairs, $pairs->all());
    }

    public function test_home_and_away_with_four_teams_generates_six_rounds(): void
    {
        $rounds = $this->service->generate($this->makeTeams(4), ScheduleFormat::HomeAndAway);

        $this->assertCount(6, $rounds);
    }

    public function test_home_and_away_with_four_teams_generates_twelve_matches(): void
    {
        $rounds = $this->service->generate($this->makeTeams(4), ScheduleFormat::HomeAndAway);

        $this->assertCount(12, $this->allFixtures($rounds));
    }

    public function test_home_and_away_with_five_teams_generates_ten_rounds(): void
    {
        $rounds = $this->service->generate($this->makeTeams(5), ScheduleFormat::HomeAndAway);

        $this->assertCount(10, $rounds);
    }

    public function test_home_and_away_with_five_teams_generates_twenty_matches(): void
    {
        $rounds = $this->service->generate($this->makeTeams(5), ScheduleFormat::HomeAndAway);

        $this->assertCount(20, $this->allFixtures($rounds));
    }

    public function test_home_and_away_never_pairs_a_team_against_itself(): void
    {
        $rounds = $this->service->generate($this->makeTeams(5), ScheduleFormat::HomeAndAway);

        foreach ($this->allFixtures($rounds) as $fixture) {
            $this->assertNotSame($fixture['home_team_id'], $fixture['away_team_id']);
        }
    }

    public function test_home_and_away_never_repeats_a_pairing_more_than_twice(): void
    {
        $rounds = $this->service->generate($this->makeTeams(5), ScheduleFormat::HomeAndAway);

        $pairCounts = $this->allFixtures($rounds)
            ->map(fn (array $fixture) => collect([$fixture['home_team_id'], $fixture['away_team_id']])->sort()->implode('-'))
            ->countBy();

        foreach ($pairCounts as $count) {
            $this->assertSame(2, $count);
        }
    }

    public function test_home_and_away_makes_every_team_face_every_rival_exactly_twice(): void
    {
        $teams = $this->makeTeams(5);
        $rounds = $this->service->generate($teams, ScheduleFormat::HomeAndAway);

        $pairCounts = $this->allFixtures($rounds)
            ->map(fn (array $fixture) => collect([$fixture['home_team_id'], $fixture['away_team_id']])->sort()->implode('-'))
            ->countBy();

        $expectedPairs = $this->allPossiblePairs($teams->pluck('id'));

        $this->assertEqualsCanonicalizing($expectedPairs, $pairCounts->keys()->all());
    }

    public function test_home_and_away_second_leg_reverses_home_and_away_from_the_first_leg(): void
    {
        $rounds = collect($this->service->generate($this->makeTeams(4), ScheduleFormat::HomeAndAway))->values();

        $firstLeg = $rounds->take(3);
        $secondLeg = $rounds->slice(3)->values();

        foreach ($firstLeg as $index => $round) {
            $mirrored = $secondLeg[$index];

            $this->assertSame(1, $round['leg']);
            $this->assertSame(2, $mirrored['leg']);

            $firstLegPairs = collect($round['fixtures'])
                ->map(fn (array $fixture) => $fixture['home_team_id'].'-'.$fixture['away_team_id'])
                ->sort()
                ->values();

            $reversedMirroredPairs = collect($mirrored['fixtures'])
                ->map(fn (array $fixture) => $fixture['away_team_id'].'-'.$fixture['home_team_id'])
                ->sort()
                ->values();

            $this->assertSame($firstLegPairs->all(), $reversedMirroredPairs->all());
        }
    }
}
