<?php

namespace Tests\Feature\Tournaments;

use App\Enums\MatchStatus;
use App\Models\Category;
use App\Models\CompetitionPhase;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TournamentMatch::winnerTeamId() is the single source of truth for who won
 * a match, used both to propagate a knockout winner into the next round and
 * to color the bracket card / pick the champion. It must consider extra time
 * and penalties, not just the regular-time score.
 */
class MatchWinnerResolutionTest extends TestCase
{
    use RefreshDatabase;

    private function makeMatch(array $attributes): TournamentMatch
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create();
        $home = Team::factory()->for($tournament)->for($category)->create();
        $away = Team::factory()->for($tournament)->for($category)->create();

        return TournamentMatch::factory()->for($phase)->create(array_merge([
            'tournament_id' => $tournament->id,
            'category_id' => $category->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
        ], $attributes));
    }

    public function test_the_regular_time_score_decides_the_winner_when_conclusive(): void
    {
        $match = $this->makeMatch(['home_score' => 2, 'away_score' => 1, 'status' => MatchStatus::Finished]);

        $this->assertSame($match->home_team_id, $match->winnerTeamId());
    }

    public function test_a_draw_with_no_extra_time_or_penalties_has_no_winner(): void
    {
        $match = $this->makeMatch(['home_score' => 1, 'away_score' => 1, 'status' => MatchStatus::Finished]);

        $this->assertNull($match->winnerTeamId());
    }

    public function test_extra_time_decides_the_winner_when_it_breaks_the_tie(): void
    {
        $match = $this->makeMatch([
            'home_score' => 1,
            'away_score' => 1,
            'home_extra_time_score' => 2,
            'away_extra_time_score' => 0,
            'status' => MatchStatus::Finished,
        ]);

        $this->assertSame($match->home_team_id, $match->winnerTeamId());
    }

    public function test_penalties_decide_the_winner_when_extra_time_is_also_tied(): void
    {
        $match = $this->makeMatch([
            'home_score' => 1,
            'away_score' => 1,
            'home_extra_time_score' => 0,
            'away_extra_time_score' => 0,
            'home_penalty_score' => 3,
            'away_penalty_score' => 4,
            'status' => MatchStatus::Finished,
        ]);

        $this->assertSame($match->away_team_id, $match->winnerTeamId());
    }

    public function test_an_unfinished_match_has_no_winner(): void
    {
        $match = $this->makeMatch(['home_score' => 2, 'away_score' => 0, 'status' => MatchStatus::InProgress]);

        $this->assertNull($match->winnerTeamId());
    }
}
