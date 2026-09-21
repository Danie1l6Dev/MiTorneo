<?php

namespace Tests\Feature\Tournaments;

use App\Models\Category;
use App\Models\Club;
use App\Models\CompetitionPhase;
use App\Models\Player;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who gets quick-add event buttons on the match edit page: the whole match
 * side's plantel, automatically -- no manual "convocar" step. That plantel
 * is Team::clubPlayersEligibleForLineup(): the team's own roster, plus a
 * player age-eligible to play UP from a younger category of the same club.
 */
class MatchRosterEligibilityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A club fielding two categories -- an older one (small birth_year_to)
     * hosting the match, and a younger one (large birth_year_to) some of
     * whose players are age-eligible to play UP into the older one.
     *
     * @return array{0: User, 1: TournamentMatch, 2: Team, 3: Team, 4: Club}
     */
    private function makeClubMatch(): array
    {
        $organizer = User::factory()->create();
        $tournament = Tournament::factory()->for($organizer)->create();
        $club = Club::factory()->for($organizer)->create();

        $youngerCategory = Category::factory()->create([
            'tournament_id' => null, 'user_id' => $organizer->id, 'name' => 'Sub-8', 'uses_groups' => false, 'birth_year_to' => 2017,
        ]);
        $olderCategory = Category::factory()->create([
            'tournament_id' => null, 'user_id' => $organizer->id, 'name' => 'Sub-12', 'uses_groups' => false, 'birth_year_to' => 2013,
        ]);

        $youngerTeam = Team::factory()->create(['club_id' => $club->id, 'category_id' => $youngerCategory->id, 'tournament_id' => null, 'group_id' => null]);
        $olderTeam = Team::factory()->create(['club_id' => $club->id, 'category_id' => $olderCategory->id, 'tournament_id' => null, 'group_id' => null]);
        $awayTeam = Team::factory()->create(['club_id' => $club->id, 'category_id' => $olderCategory->id, 'tournament_id' => null, 'group_id' => null]);

        $phase = CompetitionPhase::factory()->for($tournament)->for($olderCategory)->create();
        $match = TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $tournament->id,
            'category_id' => $olderCategory->id,
            'home_team_id' => $olderTeam->id,
            'away_team_id' => $awayTeam->id,
        ]);

        return [$organizer, $match, $olderTeam, $youngerTeam, $club];
    }

    // ── Elegibilidad (Team::clubPlayersEligibleForLineup) ──────────────────

    public function test_eligible_players_include_the_teams_own_roster_and_play_up_candidates_from_the_same_club(): void
    {
        [, $match, $olderTeam, $youngerTeam] = $this->makeClubMatch();

        $ownPlayer = Player::factory()->for($olderTeam)->create();
        $playUpPlayer = Player::factory()->for($youngerTeam)->create(['birth_date' => '2016-05-01']);

        $eligible = $olderTeam->clubPlayersEligibleForLineup();

        $this->assertTrue($eligible->contains('id', $ownPlayer->id));
        $this->assertTrue($eligible->contains('id', $playUpPlayer->id));
    }

    public function test_eligible_players_exclude_players_from_a_different_club(): void
    {
        [$organizer, , $olderTeam] = $this->makeClubMatch();

        $outsiderClub = Club::factory()->for($organizer)->create();
        $outsiderTeam = Team::factory()->create(['club_id' => $outsiderClub->id, 'category_id' => $olderTeam->category_id, 'tournament_id' => null, 'group_id' => null]);
        $outsiderPlayer = Player::factory()->for($outsiderTeam)->create();

        $eligible = $olderTeam->clubPlayersEligibleForLineup();

        $this->assertFalse($eligible->contains('id', $outsiderPlayer->id));
    }

    public function test_eligible_players_exclude_a_same_club_player_too_old_to_play_down_into_a_younger_category(): void
    {
        [, , $olderTeam, $youngerTeam] = $this->makeClubMatch();

        $tooOldForYounger = Player::factory()->for($olderTeam)->create(['birth_date' => '2012-01-01']);

        $eligible = $youngerTeam->clubPlayersEligibleForLineup();

        $this->assertFalse($eligible->contains('id', $tooOldForYounger->id));
    }

    /**
     * Player::ageEligibleForCategory() itself treats a missing birth_date as
     * "don't block" (there isn't enough data to judge either way) -- the
     * right call for enrollment, where that gets flagged separately with
     * the "dato incompleto" banner. The match roster panel has no such
     * banner, so a play-up candidate with no birth_date is excluded outright
     * instead of silently let in on that permissive default -- only someone
     * already on the team's OWN roster is shown regardless of birth_date.
     */
    public function test_a_same_club_player_with_no_birth_date_is_not_offered_as_a_play_up_candidate(): void
    {
        [, , $olderTeam, $youngerTeam] = $this->makeClubMatch();

        $noBirthDate = Player::factory()->for($youngerTeam)->create(['birth_date' => null]);

        $eligible = $olderTeam->clubPlayersEligibleForLineup();

        $this->assertFalse($eligible->contains('id', $noBirthDate->id));
    }

    public function test_a_player_with_no_birth_date_still_appears_for_their_own_teams_roster(): void
    {
        [, , $olderTeam] = $this->makeClubMatch();

        $ownPlayerNoBirthDate = Player::factory()->for($olderTeam)->create(['birth_date' => null]);

        $eligible = $olderTeam->clubPlayersEligibleForLineup();

        $this->assertTrue($eligible->contains('id', $ownPlayerNoBirthDate->id));
    }

    public function test_a_legacy_team_without_a_club_only_offers_its_own_roster(): void
    {
        $organizer = User::factory()->create();
        $tournament = Tournament::factory()->for($organizer)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $team = Team::factory()->for($tournament)->for($category)->create();
        $ownPlayer = Player::factory()->for($team)->create();

        $eligible = $team->clubPlayersEligibleForLineup();

        $this->assertCount(1, $eligible);
        $this->assertTrue($eligible->contains('id', $ownPlayer->id));
    }

    // ── Integración: eventos de un jugador que juega arriba ─────────────────

    public function test_a_play_up_eligible_player_can_register_a_goal_attributed_to_the_match_side_directly(): void
    {
        [$organizer, $match, $olderTeam, $youngerTeam] = $this->makeClubMatch();
        $playUpPlayer = Player::factory()->for($youngerTeam)->create(['birth_date' => '2016-05-01']);

        $this->actingAs($organizer)->post(route('matches.events.store', $match), [
            'type' => 'goal',
            'player_id' => $playUpPlayer->id,
        ])->assertRedirect(route('matches.edit', $match));

        // The event belongs to the OLDER team (the match side this player
        // is eligible to play up for) even though Player::$team_id still
        // points at their own younger team's roster.
        $this->assertDatabaseHas('match_events', [
            'match_id' => $match->id,
            'team_id' => $olderTeam->id,
            'player_id' => $playUpPlayer->id,
            'type' => 'goal',
        ]);
        $this->assertNotSame($youngerTeam->id, $olderTeam->id);
    }

    public function test_a_player_not_eligible_for_either_side_cannot_register_an_event(): void
    {
        [$organizer, $match] = $this->makeClubMatch();

        $outsiderClub = Club::factory()->for($organizer)->create();
        $outsiderTeam = Team::factory()->create(['club_id' => $outsiderClub->id, 'category_id' => $match->category_id, 'tournament_id' => null, 'group_id' => null]);
        $outsiderPlayer = Player::factory()->for($outsiderTeam)->create();

        $this->actingAs($organizer)->post(route('matches.events.store', $match), [
            'type' => 'goal',
            'player_id' => $outsiderPlayer->id,
        ])->assertSessionHasErrors('player_id');

        $this->assertDatabaseMissing('match_events', ['match_id' => $match->id]);
    }

    // ── Club sin jugadores ───────────────────────────────────────────────

    /**
     * A club with no players at all (nothing on any of its rosters, not
     * even the match's own home/away teams) shows the roster panel's own
     * empty state pointing the organizer at "Agregar jugador" for that
     * team.
     */
    public function test_the_edit_page_offers_to_add_players_when_the_team_has_none(): void
    {
        $organizer = User::factory()->create();
        $tournament = Tournament::factory()->for($organizer)->create();
        $club = Club::factory()->for($organizer)->create();
        $category = Category::factory()->create(['tournament_id' => null, 'user_id' => $organizer->id, 'uses_groups' => false]);

        $home = Team::factory()->create(['club_id' => $club->id, 'category_id' => $category->id, 'tournament_id' => null, 'group_id' => null]);
        $away = Team::factory()->create(['club_id' => $club->id, 'category_id' => $category->id, 'tournament_id' => null, 'group_id' => null]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create();
        $match = TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $tournament->id,
            'category_id' => $category->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
        ]);

        $response = $this->actingAs($organizer)->get(route('matches.edit', $match));

        $response->assertOk()
            ->assertSeeText(__('Este equipo todavía no tiene jugadores cargados.'))
            ->assertSee(route('teams.players.create', $home));
    }
}
