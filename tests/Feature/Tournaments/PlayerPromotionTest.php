<?php

namespace Tests\Feature\Tournaments;

use App\Models\Category;
use App\Models\Club;
use App\Models\Player;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Moving a player out of a team whose category no longer fits them
 * (Player::promotionCandidateTeams()/promoteFromTeam()) once the category's
 * allowed years were edited after they were already rostered.
 */
class PlayerPromotionTest extends TestCase
{
    use RefreshDatabase;

    public function test_promoting_a_player_linked_via_the_legacy_team_id_moves_them_to_the_destination_team(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create();

        $youngCategory = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'name' => 'Infantil', 'birth_year_from' => 2012, 'birth_year_to' => 2013]);
        $olderCategory = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'name' => 'Juvenil', 'birth_year_from' => 2008, 'birth_year_to' => 2009]);

        $originTeam = Team::factory()->create(['club_id' => $club->id, 'category_id' => $youngCategory->id, 'tournament_id' => null, 'group_id' => null]);
        $destinationTeam = Team::factory()->create(['club_id' => $club->id, 'category_id' => $olderCategory->id, 'tournament_id' => null, 'group_id' => null]);

        // Born 2010: too old for Infantil (2010 < 2013) once the category's
        // range was set/edited this way, but fits Juvenil (2010 >= 2009).
        $player = Player::factory()->for($originTeam)->create(['birth_date' => '2010-01-01']);

        $this->assertFalse($player->ageEligibleForCategory($youngCategory));
        $this->assertTrue($player->ageEligibleForCategory($olderCategory));

        $this->actingAs($user)
            ->post(route('teams.players.promote.store', [$originTeam, $player]))
            ->assertRedirect(route('teams.show', $originTeam));

        $player->refresh();

        $this->assertSame($destinationTeam->id, $player->team_id);
        $this->assertFalse($originTeam->players()->whereKey($player->id)->exists());
        $this->assertTrue($destinationTeam->players()->whereKey($player->id)->exists());
    }

    public function test_promoting_a_player_linked_via_the_pivot_moves_only_that_link(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create();

        $primaryCategory = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'name' => 'Cebollita', 'birth_year_to' => 2018]);
        $youngCategory = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'name' => 'Infantil', 'birth_year_to' => 2013]);
        $olderCategory = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'name' => 'Juvenil', 'birth_year_to' => 2009]);

        $primaryTeam = Team::factory()->create(['club_id' => $club->id, 'category_id' => $primaryCategory->id, 'tournament_id' => null, 'group_id' => null]);
        $originTeam = Team::factory()->create(['club_id' => $club->id, 'category_id' => $youngCategory->id, 'tournament_id' => null, 'group_id' => null]);
        $destinationTeam = Team::factory()->create(['club_id' => $club->id, 'category_id' => $olderCategory->id, 'tournament_id' => null, 'group_id' => null]);

        $player = Player::factory()->for($primaryTeam)->create(['birth_date' => '2010-01-01']);
        $player->teams()->attach($originTeam->id, ['jersey_number' => 7]);

        $this->actingAs($user)
            ->post(route('teams.players.promote.store', [$originTeam, $player]))
            ->assertRedirect(route('teams.show', $originTeam));

        $player->refresh();

        // The legacy team_id link (a different, still-fitting team) is left
        // untouched -- only the pivot row that pointed at $originTeam moves.
        $this->assertSame($primaryTeam->id, $player->team_id);
        $this->assertFalse($player->teams()->where('teams.id', $originTeam->id)->exists());
        $destinationLink = $player->teams()->where('teams.id', $destinationTeam->id)->first();
        $this->assertNotNull($destinationLink);
        $this->assertSame(7, $destinationLink->pivot->jersey_number);
    }

    public function test_bulk_promotion_always_moves_a_player_to_the_closest_fitting_category_even_with_several_older_ones_available(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create();

        // ageEligibleForCategory() allows playing up into ANY older
        // category with no upper limit, so a player who ages out of
        // Infantil is technically "eligible" for Juvenil AND Mayores AND
        // every category older still. Requiring a single unambiguous
        // candidate here would mean bulk promotion almost never fires once
        // a club has more than one older category -- it must always take
        // the CLOSEST one instead, see Player::promotionCandidateTeams()
        // (sorted youngest-first) and PlayerController::promoteEligible().
        $youngCategory = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'name' => 'Infantil', 'birth_year_to' => 2013]);
        $midCategory = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'name' => 'Juvenil', 'birth_year_to' => 2009]);
        $oldestCategory = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'name' => 'Mayores', 'birth_year_to' => 2005]);

        $originTeam = Team::factory()->create(['club_id' => $club->id, 'category_id' => $youngCategory->id, 'tournament_id' => null, 'group_id' => null]);
        $midTeam = Team::factory()->create(['club_id' => $club->id, 'category_id' => $midCategory->id, 'tournament_id' => null, 'group_id' => null]);
        Team::factory()->create(['club_id' => $club->id, 'category_id' => $oldestCategory->id, 'tournament_id' => null, 'group_id' => null]);

        // Too old for Infantil (2009 < 2013) but clears BOTH Juvenil's
        // (2009 >= 2009) and Mayores' (2009 >= 2005) thresholds -- promoted
        // straight to Juvenil, the nearer of the two, not left for review.
        $player = Player::factory()->for($originTeam)->create(['birth_date' => '2009-01-01']);
        // Still fits Infantil -- untouched either way.
        $stillFittingPlayer = Player::factory()->for($originTeam)->create(['birth_date' => '2013-06-01']);

        $this->actingAs($user)
            ->post(route('teams.players.promote-eligible', $originTeam))
            ->assertRedirect();

        $player->refresh();
        $stillFittingPlayer->refresh();

        $this->assertSame($midTeam->id, $player->team_id);
        $this->assertSame($originTeam->id, $stillFittingPlayer->team_id);
    }

    public function test_bulk_promotion_leaves_a_player_with_no_older_category_yet_untouched(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create();

        $youngCategory = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'name' => 'Infantil', 'birth_year_to' => 2013]);
        $originTeam = Team::factory()->create(['club_id' => $club->id, 'category_id' => $youngCategory->id, 'tournament_id' => null, 'group_id' => null]);

        // Too old for Infantil, but the club has no older category/team at
        // all yet -- nothing to promote them into.
        $player = Player::factory()->for($originTeam)->create(['birth_date' => '2005-01-01']);

        $this->actingAs($user)
            ->post(route('teams.players.promote-eligible', $originTeam))
            ->assertRedirect();

        $this->assertSame($originTeam->id, $player->refresh()->team_id);
    }

    public function test_an_own_roster_player_who_no_longer_fits_is_excluded_from_the_lineup_search(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create();
        $category = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'name' => 'Infantil', 'birth_year_to' => 2013]);
        $team = Team::factory()->create(['club_id' => $club->id, 'category_id' => $category->id, 'tournament_id' => null, 'group_id' => null]);

        $tooOldPlayer = Player::factory()->for($team)->create(['birth_date' => '2005-01-01']);
        $fittingPlayer = Player::factory()->for($team)->create(['birth_date' => '2014-01-01']);

        $eligibleIds = $team->clubPlayersEligibleForLineup()->pluck('id');

        $this->assertFalse($eligibleIds->contains($tooOldPlayer->id));
        $this->assertTrue($eligibleIds->contains($fittingPlayer->id));
        $this->assertTrue($team->ineligibleRosterPlayers()->pluck('id')->contains($tooOldPlayer->id));
    }
}
