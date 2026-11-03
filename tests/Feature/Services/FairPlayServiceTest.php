<?php

namespace Tests\Feature\Services;

use App\Enums\MatchEventType;
use App\Enums\MatchStatus;
use App\Models\Category;
use App\Models\Coach;
use App\Models\CompetitionPhase;
use App\Models\MatchEvent;
use App\Models\Player;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Services\FairPlayService;
use App\Services\StandingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class FairPlayServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tournament $tournament;

    private Category $category;

    private CompetitionPhase $phase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tournament = Tournament::factory()->create();
        $this->category = Category::factory()->for($this->tournament)->create();
        $this->phase = CompetitionPhase::factory()->for($this->tournament)->for($this->category)->create();
    }

    private function match(Team $home, Team $away, int $homeScore, int $awayScore, MatchStatus $status = MatchStatus::Finished): TournamentMatch
    {
        return TournamentMatch::factory()->for($this->phase)->create([
            'tournament_id' => $this->tournament->id,
            'category_id' => $this->category->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'home_score' => $status === MatchStatus::Finished ? $homeScore : null,
            'away_score' => $status === MatchStatus::Finished ? $awayScore : null,
            'status' => $status,
        ]);
    }

    private function card(TournamentMatch $match, Team $team, MatchEventType $type, ?Player $player = null, ?Coach $coach = null): void
    {
        MatchEvent::factory()->create([
            'match_id' => $match->id,
            'team_id' => $team->id,
            'player_id' => $coach === null ? ($player ?? Player::factory()->create())->id : null,
            'coach_id' => $coach?->id,
            'type' => $type,
        ]);
    }

    public function test_the_penalty_for_one_person_in_one_match_follows_fifa_values(): void
    {
        $service = new FairPlayService;

        $this->assertSame(0, $service->penaltyFor(0, 0));
        $this->assertSame(1, $service->penaltyFor(1, 0));   // yellow
        $this->assertSame(3, $service->penaltyFor(2, 0));   // second yellow
        $this->assertSame(3, $service->penaltyFor(2, 1));   // second yellow, with its red also recorded
        $this->assertSame(4, $service->penaltyFor(0, 1));   // direct red
        $this->assertSame(5, $service->penaltyFor(1, 1));   // yellow then direct red
    }

    public function test_a_teams_penalties_add_up_one_per_person_per_match_including_coaches(): void
    {
        [$team, $other] = Team::factory()->for($this->tournament)->for($this->category)->count(2)->create()->all();
        $match = $this->match($team, $other, 1, 0);
        $second = $this->match($team, $other, 0, 0);

        [$yellow, $doubleYellow, $yellowThenRed, $directRed] = Player::factory()->count(4)->create()->all();
        $coach = Coach::factory()->create(['team_id' => $team->id]);

        $this->card($match, $team, MatchEventType::YellowCard, $yellow);                  // 1
        $this->card($match, $team, MatchEventType::YellowCard, $doubleYellow);
        $this->card($match, $team, MatchEventType::YellowCard, $doubleYellow);            // 3
        $this->card($match, $team, MatchEventType::YellowCard, $yellowThenRed);
        $this->card($match, $team, MatchEventType::RedCard, $yellowThenRed);              // 5
        $this->card($match, $team, MatchEventType::RedCard, $directRed);                  // 4
        $this->card($match, $team, MatchEventType::YellowCard, coach: $coach);            // 1 (cuerpo técnico)
        $this->card($second, $team, MatchEventType::YellowCard, $yellow);                 // 1, another match

        $penalties = (new FairPlayService)->penaltiesByTeam(collect([$match, $second]));

        $this->assertSame(15, $penalties[$team->id]);
        $this->assertArrayNotHasKey($other->id, $penalties);
    }

    public function test_cards_from_matches_that_are_not_finished_do_not_count(): void
    {
        [$team, $other] = Team::factory()->for($this->tournament)->for($this->category)->count(2)->create()->all();
        $scheduled = $this->match($team, $other, 0, 0, MatchStatus::Scheduled);
        $this->card($scheduled, $team, MatchEventType::RedCard);

        $this->assertSame([], (new FairPlayService)->penaltiesByTeam(collect([$scheduled])));
    }

    /**
     * @return Collection<int, Team>
     */
    private function twoLevelTeams(): Collection
    {
        return Team::factory()->for($this->tournament)->for($this->category)->count(2)->create()->values();
    }

    public function test_fair_play_breaks_a_tie_left_after_every_other_criterion(): void
    {
        [$a, $b] = $this->twoLevelTeams()->all();
        $draw = $this->match($a, $b, 1, 1);

        // Level on points, goal difference, goals for and head-to-head: with
        // no cards the input order stands.
        $service = new StandingsService;
        $rows = $service->calculate(collect([$a, $b]), collect([$draw]));
        $this->assertSame([$a->id, $b->id], array_map(fn (array $row): int => $row['team']->id, $rows));

        // A yellow card for A: B has the better fair play, so B goes first.
        $this->card($draw, $a, MatchEventType::YellowCard);

        $rows = $service->calculate(collect([$a, $b]), collect([$draw]));
        $this->assertSame([$b->id, $a->id], array_map(fn (array $row): int => $row['team']->id, $rows));
        $this->assertSame([0, 1], array_map(fn (array $row): int => $row['fair_play'], $rows));
    }

    public function test_fair_play_never_overrides_the_earlier_criteria(): void
    {
        [$a, $b] = $this->twoLevelTeams()->all();
        $win = $this->match($a, $b, 1, 0);

        // A wins on points, but is the one shown a red card.
        $this->card($win, $a, MatchEventType::RedCard);

        $rows = (new StandingsService)->calculate(collect([$a, $b]), collect([$win]));

        $this->assertSame([$a->id, $b->id], array_map(fn (array $row): int => $row['team']->id, $rows));
    }

    public function test_the_standings_table_shows_a_fair_play_column_only_once_someone_has_penalties(): void
    {
        $view = fn (array $rows): string => $this->blade('<x-ui.standings-table :rows="$rows" />', ['rows' => $rows])->__toString();

        [$a, $b] = $this->twoLevelTeams()->all();
        $draw = $this->match($a, $b, 1, 1);
        $service = new StandingsService;

        $this->assertStringNotContainsString('>JL<', $view($service->calculate(collect([$a, $b]), collect([$draw]))));

        $this->card($draw, $a, MatchEventType::YellowCard);

        $this->assertStringContainsString('JL', $view($service->calculate(collect([$a, $b]), collect([$draw]))));
    }
}
