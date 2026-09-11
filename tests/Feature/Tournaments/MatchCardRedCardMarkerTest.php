<?php

namespace Tests\Feature\Tournaments;

use App\Enums\CompetitionPhaseType;
use App\Enums\MatchEventType;
use App\Enums\MatchStatus;
use App\Models\Category;
use App\Models\CompetitionPhase;
use App\Models\LeagueSchedule;
use App\Models\MatchEvent;
use App\Models\Player;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A match with at least one red-card event (a straight red or an auto
 * double-yellow) shows small red-card icons UNDER (x-ui.match-card) or NEXT
 * TO (x-ui.bracket-match-card, whose rows are too compact for "under") the
 * name of whichever team the expelled player/coach belonged to -- so an
 * expulsion is visible, and attributed to the right side, from the calendar
 * or the knockout bracket without opening the match
 * (TournamentMatch::redCardCountForTeam()).
 */
class MatchCardRedCardMarkerTest extends TestCase
{
    use RefreshDatabase;

    // A marker's title attribute reads "1 expulsado"/"2 expulsados" -- only
    // ever rendered inside that title, so a bare "expulsado" substring is a
    // safe stand-in for "the marker is present somewhere on the page".
    private const MARKER_WORD = 'expulsado';

    // ── Modelo ───────────────────────────────────────────────────────────

    public function test_has_red_card_is_false_with_no_red_card_events(): void
    {
        $match = TournamentMatch::factory()->create();

        $this->assertFalse($match->hasRedCard());
    }

    public function test_has_red_card_is_true_once_a_red_card_event_exists(): void
    {
        $match = TournamentMatch::factory()->create();
        $player = Player::factory()->for($match->homeTeam)->create();

        MatchEvent::factory()->create([
            'match_id' => $match->id,
            'team_id' => $match->home_team_id,
            'player_id' => $player->id,
            'type' => MatchEventType::RedCard,
        ]);

        $this->assertTrue($match->hasRedCard());
    }

    public function test_has_red_card_works_whether_or_not_the_relation_is_eager_loaded(): void
    {
        $match = TournamentMatch::factory()->create();
        $player = Player::factory()->for($match->homeTeam)->create();

        MatchEvent::factory()->create([
            'match_id' => $match->id,
            'team_id' => $match->home_team_id,
            'player_id' => $player->id,
            'type' => MatchEventType::RedCard,
        ]);

        $withRelation = TournamentMatch::with('redCards')->find($match->id);
        $this->assertTrue($withRelation->relationLoaded('redCards'));
        $this->assertTrue($withRelation->hasRedCard());

        $withoutRelation = TournamentMatch::find($match->id);
        $this->assertFalse($withoutRelation->relationLoaded('redCards'));
        $this->assertTrue($withoutRelation->hasRedCard());
    }

    public function test_red_card_count_for_team_is_scoped_to_that_team_only(): void
    {
        $match = TournamentMatch::factory()->create();
        $homePlayer = Player::factory()->for($match->homeTeam)->create();

        MatchEvent::factory()->create([
            'match_id' => $match->id,
            'team_id' => $match->home_team_id,
            'player_id' => $homePlayer->id,
            'type' => MatchEventType::RedCard,
        ]);

        $this->assertSame(1, $match->redCardCountForTeam($match->home_team_id));
        $this->assertSame(0, $match->redCardCountForTeam($match->away_team_id));
    }

    public function test_red_card_count_for_team_counts_more_than_one(): void
    {
        $match = TournamentMatch::factory()->create();
        $playerOne = Player::factory()->for($match->homeTeam)->create();
        $playerTwo = Player::factory()->for($match->homeTeam)->create();

        foreach ([$playerOne, $playerTwo] as $player) {
            MatchEvent::factory()->create([
                'match_id' => $match->id,
                'team_id' => $match->home_team_id,
                'player_id' => $player->id,
                'type' => MatchEventType::RedCard,
            ]);
        }

        $this->assertSame(2, $match->redCardCountForTeam($match->home_team_id));
    }

    // ── Calendario (fase de liga) ────────────────────────────────────────

    private function makeLeagueMatchWithSchedule(User $user): array
    {
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['type' => CompetitionPhaseType::League]);
        $home = Team::factory()->for($tournament)->for($category)->create();
        $away = Team::factory()->for($tournament)->for($category)->create();

        $schedule = LeagueSchedule::factory()->for($phase, 'competitionPhase')->for($tournament)->create();

        $match = TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $tournament->id,
            'category_id' => $category->id,
            'league_schedule_id' => $schedule->id,
            'round_number' => 1,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'home_score' => 1,
            'away_score' => 0,
            'status' => MatchStatus::Finished,
        ]);

        return [$phase, $match, $home];
    }

    public function test_the_calendar_marks_a_match_that_had_a_red_card(): void
    {
        $user = User::factory()->create();
        [$phase, $match, $home] = $this->makeLeagueMatchWithSchedule($user);
        $player = Player::factory()->for($home)->create();

        MatchEvent::factory()->create([
            'match_id' => $match->id,
            'team_id' => $home->id,
            'player_id' => $player->id,
            'type' => MatchEventType::RedCard,
        ]);

        $this->actingAs($user)
            ->get(route('phases.show', $phase))
            ->assertOk()
            ->assertSee(self::MARKER_WORD);
    }

    public function test_the_calendar_does_not_mark_a_match_with_no_red_card(): void
    {
        $user = User::factory()->create();
        [$phase] = $this->makeLeagueMatchWithSchedule($user);

        $this->actingAs($user)
            ->get(route('phases.show', $phase))
            ->assertOk()
            ->assertDontSee(self::MARKER_WORD);
    }

    public function test_a_yellow_card_alone_does_not_trigger_the_red_card_marker(): void
    {
        $user = User::factory()->create();
        [$phase, $match, $home] = $this->makeLeagueMatchWithSchedule($user);
        $player = Player::factory()->for($home)->create();

        MatchEvent::factory()->create([
            'match_id' => $match->id,
            'team_id' => $home->id,
            'player_id' => $player->id,
            'type' => MatchEventType::YellowCard,
        ]);

        $this->actingAs($user)
            ->get(route('phases.show', $phase))
            ->assertOk()
            ->assertDontSee(self::MARKER_WORD);
    }

    // ── Cuadro de eliminación ────────────────────────────────────────────

    public function test_the_bracket_marks_a_cross_that_had_a_red_card(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['type' => CompetitionPhaseType::Knockout]);
        $home = Team::factory()->for($tournament)->for($category)->create();
        $away = Team::factory()->for($tournament)->for($category)->create();

        $match = TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $tournament->id,
            'category_id' => $category->id,
            'round_number' => 1,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'home_score' => 1,
            'away_score' => 0,
            'status' => MatchStatus::Finished,
        ]);

        $player = Player::factory()->for($away)->create();

        MatchEvent::factory()->create([
            'match_id' => $match->id,
            'team_id' => $away->id,
            'player_id' => $player->id,
            'type' => MatchEventType::RedCard,
        ]);

        $this->actingAs($user)
            ->get(route('phases.show', $phase))
            ->assertOk()
            ->assertSee(self::MARKER_WORD);
    }

    // ── Portal público ───────────────────────────────────────────────────

    public function test_the_public_calendar_also_marks_a_match_that_had_a_red_card(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create(['slug' => 'torneo-tarjeta-roja']);
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['type' => CompetitionPhaseType::League]);
        $home = Team::factory()->for($tournament)->for($category)->create();
        $away = Team::factory()->for($tournament)->for($category)->create();
        $schedule = LeagueSchedule::factory()->for($phase, 'competitionPhase')->for($tournament)->create();

        $match = TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $tournament->id,
            'category_id' => $category->id,
            'league_schedule_id' => $schedule->id,
            'round_number' => 1,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'home_score' => 1,
            'away_score' => 0,
            'status' => MatchStatus::Finished,
        ]);

        $player = Player::factory()->for($home)->create();

        MatchEvent::factory()->create([
            'match_id' => $match->id,
            'team_id' => $home->id,
            'player_id' => $player->id,
            'type' => MatchEventType::RedCard,
        ]);

        $this->get(route('public.tournaments.phases.show', [$tournament, $phase]))
            ->assertOk()
            ->assertSee(self::MARKER_WORD);
    }
}
