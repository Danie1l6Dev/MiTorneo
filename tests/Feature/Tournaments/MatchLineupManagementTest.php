<?php

namespace Tests\Feature\Tournaments;

use App\Enums\MatchEventType;
use App\Models\Category;
use App\Models\Club;
use App\Models\CompetitionPhase;
use App\Models\MatchEvent;
use App\Models\MatchLineup;
use App\Models\Player;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The match-day lineup ("convocatoria"): who actually gets quick-add event
 * buttons on the match edit page is no longer a team's whole category
 * plantel, it's whoever was searched for and added here -- including a
 * player called up to play UP from a younger category of the same club
 * (Player::ageEligibleForCategory()). See MatchLineup's docblock.
 */
class MatchLineupManagementTest extends TestCase
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
     * the "dato incompleto" banner. This search has no such banner, so a
     * play-up candidate with no birth_date is excluded outright instead of
     * silently let in on that permissive default -- only someone already
     * on the team's OWN roster is shown regardless of birth_date.
     */
    public function test_a_same_club_player_with_no_birth_date_is_not_offered_as_a_play_up_candidate(): void
    {
        [, , $olderTeam, $youngerTeam] = $this->makeClubMatch();

        $noBirthDate = Player::factory()->for($youngerTeam)->create(['birth_date' => null]);

        $eligible = $olderTeam->clubPlayersEligibleForLineup();

        $this->assertFalse($eligible->contains('id', $noBirthDate->id));
    }

    public function test_a_player_with_no_birth_date_still_appears_for_their_own_teams_lineup(): void
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

    // ── Agregar convocados (store) ──────────────────────────────────────────

    public function test_a_user_can_add_a_play_up_eligible_player_to_the_lineup(): void
    {
        [$organizer, $match, $olderTeam, $youngerTeam] = $this->makeClubMatch();
        $playUpPlayer = Player::factory()->for($youngerTeam)->create(['birth_date' => '2016-05-01']);

        $this->actingAs($organizer)->post(route('matches.lineups.store', $match), [
            'team_id' => $olderTeam->id,
            'player_ids' => [$playUpPlayer->id],
        ])->assertRedirect(route('matches.edit', $match));

        $this->assertDatabaseHas('match_lineups', [
            'match_id' => $match->id,
            'team_id' => $olderTeam->id,
            'player_id' => $playUpPlayer->id,
        ]);
    }

    public function test_a_user_cannot_add_a_player_who_isnt_eligible_for_the_team(): void
    {
        [$organizer, $match, $olderTeam] = $this->makeClubMatch();

        $outsiderClub = Club::factory()->for($organizer)->create();
        $outsiderTeam = Team::factory()->create(['club_id' => $outsiderClub->id, 'category_id' => $olderTeam->category_id, 'tournament_id' => null, 'group_id' => null]);
        $outsiderPlayer = Player::factory()->for($outsiderTeam)->create();

        $this->actingAs($organizer)->post(route('matches.lineups.store', $match), [
            'team_id' => $olderTeam->id,
            'player_ids' => [$outsiderPlayer->id],
        ])->assertSessionHasErrors('player_ids');

        $this->assertDatabaseMissing('match_lineups', ['match_id' => $match->id]);
    }

    public function test_a_user_cannot_add_players_for_a_team_id_thats_not_one_of_the_matchs_two_sides(): void
    {
        [$organizer, $match, , $youngerTeam] = $this->makeClubMatch();
        $player = Player::factory()->for($youngerTeam)->create();

        $this->actingAs($organizer)->post(route('matches.lineups.store', $match), [
            'team_id' => $youngerTeam->id,
            'player_ids' => [$player->id],
        ])->assertSessionHasErrors('team_id');
    }

    public function test_adding_an_already_called_up_player_again_does_not_duplicate_the_row(): void
    {
        [$organizer, $match, $olderTeam] = $this->makeClubMatch();
        $player = Player::factory()->for($olderTeam)->create();

        MatchLineup::factory()->create(['match_id' => $match->id, 'team_id' => $olderTeam->id, 'player_id' => $player->id]);

        $this->actingAs($organizer)->post(route('matches.lineups.store', $match), [
            'team_id' => $olderTeam->id,
            'player_ids' => [$player->id],
        ])->assertRedirect(route('matches.edit', $match));

        $this->assertSame(1, MatchLineup::query()->where('match_id', $match->id)->where('player_id', $player->id)->count());
    }

    public function test_a_user_cannot_add_lineup_players_on_another_users_match(): void
    {
        [, $match, $olderTeam] = $this->makeClubMatch();
        $player = Player::factory()->for($olderTeam)->create();
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->post(route('matches.lineups.store', $match), [
            'team_id' => $olderTeam->id,
            'player_ids' => [$player->id],
        ])->assertForbidden();

        $this->assertDatabaseMissing('match_lineups', ['match_id' => $match->id]);
    }

    // ── Quitar de la convocatoria (destroy) ─────────────────────────────────

    public function test_a_lineup_entry_without_events_can_be_removed(): void
    {
        [$organizer, $match, $olderTeam] = $this->makeClubMatch();
        $player = Player::factory()->for($olderTeam)->create();
        $lineup = MatchLineup::factory()->create(['match_id' => $match->id, 'team_id' => $olderTeam->id, 'player_id' => $player->id]);

        $this->actingAs($organizer)->delete(route('lineups.destroy', $lineup))
            ->assertRedirect(route('matches.edit', $match));

        $this->assertDatabaseMissing('match_lineups', ['id' => $lineup->id]);
    }

    public function test_a_lineup_entry_with_events_cannot_be_removed(): void
    {
        [$organizer, $match, $olderTeam] = $this->makeClubMatch();
        $player = Player::factory()->for($olderTeam)->create();
        $lineup = MatchLineup::factory()->create(['match_id' => $match->id, 'team_id' => $olderTeam->id, 'player_id' => $player->id]);

        MatchEvent::factory()->create([
            'match_id' => $match->id,
            'team_id' => $olderTeam->id,
            'player_id' => $player->id,
            'type' => MatchEventType::Goal,
        ]);

        $this->actingAs($organizer)->delete(route('lineups.destroy', $lineup))
            ->assertRedirect(route('matches.edit', $match))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('match_lineups', ['id' => $lineup->id]);
    }

    public function test_a_user_cannot_remove_a_lineup_entry_from_another_users_match(): void
    {
        [, $match, $olderTeam] = $this->makeClubMatch();
        $player = Player::factory()->for($olderTeam)->create();
        $lineup = MatchLineup::factory()->create(['match_id' => $match->id, 'team_id' => $olderTeam->id, 'player_id' => $player->id]);
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->delete(route('lineups.destroy', $lineup))->assertForbidden();

        $this->assertDatabaseHas('match_lineups', ['id' => $lineup->id]);
    }

    // ── Integración: eventos de un jugador que juega arriba ─────────────────

    public function test_a_play_up_player_called_up_for_the_match_can_register_a_goal_attributed_to_the_match_side(): void
    {
        [$organizer, $match, $olderTeam, $youngerTeam] = $this->makeClubMatch();
        $playUpPlayer = Player::factory()->for($youngerTeam)->create(['birth_date' => '2016-05-01']);

        MatchLineup::factory()->create(['match_id' => $match->id, 'team_id' => $olderTeam->id, 'player_id' => $playUpPlayer->id]);

        $this->actingAs($organizer)->post(route('matches.events.store', $match), [
            'type' => 'goal',
            'player_id' => $playUpPlayer->id,
        ])->assertRedirect(route('matches.edit', $match));

        // The event belongs to the OLDER team (the match side this player
        // was called up for) even though Player::$team_id still points at
        // their own younger team's roster.
        $this->assertDatabaseHas('match_events', [
            'match_id' => $match->id,
            'team_id' => $olderTeam->id,
            'player_id' => $playUpPlayer->id,
            'type' => 'goal',
        ]);
        $this->assertNotSame($youngerTeam->id, $olderTeam->id);
    }

    public function test_a_play_up_player_not_called_up_for_the_match_cannot_register_an_event(): void
    {
        [$organizer, $match, , $youngerTeam] = $this->makeClubMatch();
        $playUpPlayer = Player::factory()->for($youngerTeam)->create(['birth_date' => '2016-05-01']);

        $this->actingAs($organizer)->post(route('matches.events.store', $match), [
            'type' => 'goal',
            'player_id' => $playUpPlayer->id,
        ])->assertSessionHasErrors('player_id');

        $this->assertDatabaseMissing('match_events', ['match_id' => $match->id]);
    }

    // ── Club sin jugadores ───────────────────────────────────────────────

    /**
     * A club with no players at all (nothing on any of its rosters, not
     * even the match's own home/away teams) has nothing to search --
     * rather than an empty, unexplained search box, the panel points the
     * organizer at "Agregar jugador" for that team instead. Reported after
     * a real user hit exactly this on a club with zero players loaded yet.
     */
    public function test_the_edit_page_offers_to_add_players_when_the_teams_club_has_none(): void
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
            ->assertSeeText(__('El club de :team todavía no tiene jugadores cargados para la categoría :category.', ['team' => $home->name, 'category' => $category->name]))
            ->assertSee(route('teams.players.create', $home));
    }
}
