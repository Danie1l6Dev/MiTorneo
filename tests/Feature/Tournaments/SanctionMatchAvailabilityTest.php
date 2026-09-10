<?php

namespace Tests\Feature\Tournaments;

use App\Enums\CompetitionPhaseType;
use App\Enums\MatchStatus;
use App\Enums\SanctionType;
use App\Models\Category;
use App\Models\Coach;
use App\Models\CompetitionPhase;
use App\Models\MatchEvent;
use App\Models\Player;
use App\Models\Sanction;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A suspension is tracked per player/coach, never per phase -- a red card
 * shown in the last matchday of the league phase must still keep that
 * player out once their team advances to the knockout phase, since nothing
 * about the sanction itself is tied to the league phase it originated in.
 * These tests cover both ends of that: the "Jugadores no disponibles" panel
 * TournamentMatchController::edit() builds, and the server-side block in
 * MatchEventRequest/MatchEventBatchRequest that keeps a suspended subject
 * from getting new events anywhere but the match that suspended them.
 */
class SanctionMatchAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Tournament, 1: Category, 2: Team, 3: Team, 4: Player}
     */
    private function makeTournamentWithTeams(User $user): array
    {
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $home = Team::factory()->for($tournament)->for($category)->create();
        $away = Team::factory()->for($tournament)->for($category)->create();
        $player = Player::factory()->for($home)->create(['jersey_number' => 9]);

        return [$tournament, $category, $home, $away, $player];
    }

    /**
     * $order feeds CompetitionPhase::order -- the same organizer-controlled
     * field PhaseEligibilityService already uses to know which phase comes
     * next, and what Sanction::teamMatchSequence() sorts by first. Passing
     * an explicit, increasing value per call is what makes ordering in
     * these tests deterministic instead of relying on row-insertion order.
     */
    private function makeMatch(Tournament $tournament, Category $category, Team $home, Team $away, CompetitionPhaseType $phaseType = CompetitionPhaseType::League, int $order = 0, MatchStatus $status = MatchStatus::Scheduled): TournamentMatch
    {
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['type' => $phaseType, 'order' => $order]);

        return TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $tournament->id,
            'category_id' => $category->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'status' => $status,
        ]);
    }

    private function sanctionPlayer(TournamentMatch $originMatch, Player $player, int $matchesBanned = 3): Sanction
    {
        return Sanction::factory()->resolved($matchesBanned)->create([
            'match_id' => $originMatch->id,
            'match_event_id' => MatchEvent::factory()->create([
                'match_id' => $originMatch->id, 'team_id' => $player->team_id, 'player_id' => $player->id, 'type' => 'red_card',
            ]),
            'team_id' => $player->team_id,
            'player_id' => $player->id,
            'type' => SanctionType::RedCard,
        ]);
    }

    // ── Panel "Jugadores no disponibles" ────────────────────────────────

    public function test_a_suspended_player_shows_up_as_unavailable_in_a_later_match_of_a_different_phase(): void
    {
        $user = User::factory()->create();
        [$tournament, $category, $home, $away, $player] = $this->makeTournamentWithTeams($user);

        $leagueMatch = $this->makeMatch($tournament, $category, $home, $away);
        $sanction = $this->sanctionPlayer($leagueMatch, $player);

        $knockoutMatch = $this->makeMatch($tournament, $category, $home, $away, CompetitionPhaseType::Knockout, order: 1);

        $this->actingAs($user)->get(route('matches.edit', $knockoutMatch))
            ->assertOk()
            ->assertViewHas('homeUnavailableSanctions', function ($sanctions) use ($sanction) {
                return $sanctions->pluck('id')->contains($sanction->id);
            });
    }

    public function test_a_suspended_player_is_not_listed_as_unavailable_in_the_match_that_originated_the_sanction(): void
    {
        $user = User::factory()->create();
        [$tournament, $category, $home, $away, $player] = $this->makeTournamentWithTeams($user);

        $leagueMatch = $this->makeMatch($tournament, $category, $home, $away);
        $this->sanctionPlayer($leagueMatch, $player);

        $this->actingAs($user)->get(route('matches.edit', $leagueMatch))
            ->assertOk()
            ->assertViewHas('homeUnavailableSanctions', fn ($sanctions) => $sanctions->isEmpty());
    }

    public function test_a_player_with_a_fulfilled_sanction_is_no_longer_listed_as_unavailable(): void
    {
        $user = User::factory()->create();
        [$tournament, $category, $home, $away, $player] = $this->makeTournamentWithTeams($user);

        $leagueMatch = $this->makeMatch($tournament, $category, $home, $away);
        $sanction = $this->sanctionPlayer($leagueMatch, $player, 1);

        // The single fecha this 1-match ban covers -- already Finished, so
        // it's automatically counted as served, no manual step involved.
        $this->makeMatch($tournament, $category, $home, $away, CompetitionPhaseType::Knockout, order: 1, status: MatchStatus::Finished);

        $laterMatch = $this->makeMatch($tournament, $category, $home, $away, CompetitionPhaseType::Knockout, order: 2);

        $this->actingAs($user)->get(route('matches.edit', $laterMatch))
            ->assertOk()
            ->assertViewHas('homeUnavailableSanctions', fn ($sanctions) => $sanctions->isEmpty());
    }

    public function test_a_coach_still_pending_resolution_shows_up_as_unavailable_and_hides_the_dt_row(): void
    {
        $user = User::factory()->create();
        [$tournament, $category, $home, $away] = $this->makeTournamentWithTeams($user);
        $coach = Coach::factory()->for($home)->create();

        $leagueMatch = $this->makeMatch($tournament, $category, $home, $away);
        Sanction::factory()->create([
            'match_id' => $leagueMatch->id,
            'match_event_id' => MatchEvent::factory()->create([
                'match_id' => $leagueMatch->id, 'team_id' => $home->id, 'coach_id' => $coach->id, 'player_id' => null, 'type' => 'red_card',
            ]),
            'team_id' => $home->id,
            'player_id' => null,
            'coach_id' => $coach->id,
            'type' => SanctionType::RedCard,
        ]);

        $knockoutMatch = $this->makeMatch($tournament, $category, $home, $away, CompetitionPhaseType::Knockout, order: 1);

        $this->actingAs($user)->get(route('matches.edit', $knockoutMatch))
            ->assertOk()
            ->assertViewHas('homeUnavailableSanctions', fn ($sanctions) => $sanctions->pluck('coach_id')->contains($coach->id));
    }

    // ── Bloqueo de eventos para un jugador/DT sancionado ────────────────

    public function test_an_event_cannot_be_registered_for_a_suspended_player_in_a_different_match(): void
    {
        $user = User::factory()->create();
        [$tournament, $category, $home, $away, $player] = $this->makeTournamentWithTeams($user);

        $leagueMatch = $this->makeMatch($tournament, $category, $home, $away);
        $this->sanctionPlayer($leagueMatch, $player);

        $knockoutMatch = $this->makeMatch($tournament, $category, $home, $away, CompetitionPhaseType::Knockout, order: 1);

        $this->actingAs($user)->post(route('matches.events.store', $knockoutMatch), [
            'type' => 'goal',
            'player_id' => $player->id,
        ])->assertSessionHasErrors('player_id');

        $this->assertDatabaseMissing('match_events', ['match_id' => $knockoutMatch->id]);
    }

    public function test_a_batch_cannot_register_an_event_for_a_suspended_player_in_a_different_match(): void
    {
        $user = User::factory()->create();
        [$tournament, $category, $home, $away, $player] = $this->makeTournamentWithTeams($user);

        $leagueMatch = $this->makeMatch($tournament, $category, $home, $away);
        $this->sanctionPlayer($leagueMatch, $player);

        $knockoutMatch = $this->makeMatch($tournament, $category, $home, $away, CompetitionPhaseType::Knockout, order: 1);

        $this->actingAs($user)->post(route('matches.events.batch-store', $knockoutMatch), [
            'events' => [
                ['type' => 'goal', 'player_id' => $player->id],
            ],
        ])->assertSessionHasErrors('events.0.player_id');

        $this->assertDatabaseMissing('match_events', ['match_id' => $knockoutMatch->id]);
    }

    public function test_an_event_can_still_be_registered_for_the_suspended_player_in_the_match_that_originated_the_sanction(): void
    {
        $user = User::factory()->create();
        [$tournament, $category, $home, $away, $player] = $this->makeTournamentWithTeams($user);

        $leagueMatch = $this->makeMatch($tournament, $category, $home, $away);
        $this->sanctionPlayer($leagueMatch, $player);

        // A goal recorded before the expulsion, backfilled afterward -- the
        // subject played this match in full up to the red card, so it's
        // never blocked, unlike a different, later match.
        $this->actingAs($user)->post(route('matches.events.store', $leagueMatch), [
            'type' => 'goal',
            'player_id' => $player->id,
        ])->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('match_events', [
            'match_id' => $leagueMatch->id, 'player_id' => $player->id, 'type' => 'goal',
        ]);
    }

    public function test_an_event_can_be_registered_again_once_the_players_sanction_is_fulfilled(): void
    {
        $user = User::factory()->create();
        [$tournament, $category, $home, $away, $player] = $this->makeTournamentWithTeams($user);

        $leagueMatch = $this->makeMatch($tournament, $category, $home, $away);
        $this->sanctionPlayer($leagueMatch, $player, 1);

        // The single fecha this 1-match ban covers -- already Finished, so
        // it's automatically counted as served, no manual step involved.
        $this->makeMatch($tournament, $category, $home, $away, CompetitionPhaseType::Knockout, order: 1, status: MatchStatus::Finished);

        $laterMatch = $this->makeMatch($tournament, $category, $home, $away, CompetitionPhaseType::Knockout, order: 2);

        $this->actingAs($user)->post(route('matches.events.store', $laterMatch), [
            'type' => 'goal',
            'player_id' => $player->id,
        ])->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('match_events', [
            'match_id' => $laterMatch->id, 'player_id' => $player->id, 'type' => 'goal',
        ]);
    }

    public function test_a_coachs_card_cannot_be_registered_in_a_different_match_while_suspended(): void
    {
        $user = User::factory()->create();
        [$tournament, $category, $home, $away] = $this->makeTournamentWithTeams($user);
        $coach = Coach::factory()->for($home)->create();

        $leagueMatch = $this->makeMatch($tournament, $category, $home, $away);
        Sanction::factory()->create([
            'match_id' => $leagueMatch->id,
            'match_event_id' => MatchEvent::factory()->create([
                'match_id' => $leagueMatch->id, 'team_id' => $home->id, 'coach_id' => $coach->id, 'player_id' => null, 'type' => 'red_card',
            ]),
            'team_id' => $home->id,
            'player_id' => null,
            'coach_id' => $coach->id,
            'type' => SanctionType::RedCard,
        ]);

        $knockoutMatch = $this->makeMatch($tournament, $category, $home, $away, CompetitionPhaseType::Knockout, order: 1);

        $this->actingAs($user)->post(route('matches.events.store', $knockoutMatch), [
            'type' => 'yellow_card',
            'coach_id' => $coach->id,
        ])->assertSessionHasErrors('coach_id');
    }
}
