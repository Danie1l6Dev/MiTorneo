<?php

namespace Tests\Feature\Tournaments;

use App\Models\Category;
use App\Models\Club;
use App\Models\Player;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every place categories are listed sorts youngest-to-oldest by their own
 * age range (Category::scopeOrderedByAge()) -- a LARGER birth_year_to means
 * a YOUNGER category (its kids were born later), the same convention
 * Player::ageEligibleForCategory() already uses. A category with no age
 * range configured yet sorts last, then by name.
 */
class CategoryAgeOrderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_scope_orders_categories_youngest_first(): void
    {
        $user = User::factory()->create();
        $sub12 = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'name' => 'Sub-12', 'birth_year_to' => 2013]);
        $sub8 = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'name' => 'Sub-8', 'birth_year_to' => 2017]);
        $sub10 = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'name' => 'Sub-10', 'birth_year_to' => 2015]);

        $ordered = Category::query()->where('user_id', $user->id)->orderedByAge()->pluck('id');

        $this->assertSame([$sub8->id, $sub10->id, $sub12->id], $ordered->all());
    }

    public function test_a_category_with_no_age_range_sorts_last(): void
    {
        $user = User::factory()->create();
        $noAge = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'name' => 'Sin Edad', 'birth_year_to' => null]);
        $sub8 = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'name' => 'Sub-8', 'birth_year_to' => 2017]);

        $ordered = Category::query()->where('user_id', $user->id)->orderedByAge()->pluck('id');

        $this->assertSame([$sub8->id, $noAge->id], $ordered->all());
    }

    public function test_categories_with_the_same_birth_year_to_break_ties_by_name(): void
    {
        $user = User::factory()->create();
        $zed = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'name' => 'Zed', 'birth_year_to' => 2015]);
        $ana = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'name' => 'Ana', 'birth_year_to' => 2015]);

        $ordered = Category::query()->where('user_id', $user->id)->orderedByAge()->pluck('id');

        $this->assertSame([$ana->id, $zed->id], $ordered->all());
    }

    public function test_the_global_category_catalog_page_lists_youngest_first(): void
    {
        $user = User::factory()->create();
        Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'name' => 'Sub-12', 'birth_year_to' => 2013]);
        Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'name' => 'Sub-8', 'birth_year_to' => 2017]);

        $this->actingAs($user)->get(route('categories.index'))
            ->assertOk()
            ->assertSeeInOrder(['SUB-8', 'SUB-12']);
    }

    public function test_a_tournaments_own_category_list_is_youngest_first(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $sub12 = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'name' => 'Sub-12', 'birth_year_to' => 2013]);
        $sub8 = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'name' => 'Sub-8', 'birth_year_to' => 2017]);
        $tournament->globalCategories()->attach([$sub12->id, $sub8->id]);

        $this->actingAs($user)->get(route('tournaments.show', $tournament))
            ->assertOk()
            ->assertSeeInOrder(['SUB-8', 'SUB-12']);
    }

    public function test_team_checkboxes_when_registering_a_club_player_are_youngest_category_first(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create();
        $sub12 = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'name' => 'Sub-12', 'birth_year_to' => 2013]);
        $sub8 = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'name' => 'Sub-8', 'birth_year_to' => 2017]);
        Team::factory()->create(['club_id' => $club->id, 'category_id' => $sub12->id, 'tournament_id' => null, 'group_id' => null]);
        Team::factory()->create(['club_id' => $club->id, 'category_id' => $sub8->id, 'tournament_id' => null, 'group_id' => null]);

        $this->actingAs($user)->get(route('clubs.players.create', $club))
            ->assertOk()
            ->assertSeeInOrder(['SUB-8', 'SUB-12']);
    }

    public function test_enrollment_checkboxes_on_a_players_edit_page_are_youngest_category_first(): void
    {
        // A THIRD category (the player's own, already-linked team) is
        // deliberately outside the two being compared -- an already-linked
        // team is never itself a "candidate" to check, see
        // Player::candidateTeamsForEnrollment().
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create();
        $ownCategory = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'name' => 'Sub-6', 'birth_year_to' => 2019]);
        $sub12 = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'name' => 'Sub-12', 'birth_year_to' => 2013]);
        $sub8 = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'name' => 'Sub-8', 'birth_year_to' => 2017]);
        $homeTeam = Team::factory()->create(['club_id' => $club->id, 'category_id' => $ownCategory->id, 'tournament_id' => null, 'group_id' => null]);
        Team::factory()->create(['club_id' => $club->id, 'category_id' => $sub12->id, 'tournament_id' => null, 'group_id' => null]);
        Team::factory()->create(['club_id' => $club->id, 'category_id' => $sub8->id, 'tournament_id' => null, 'group_id' => null]);
        $player = Player::factory()->for($homeTeam)->create(['birth_date' => '2016-01-01']);

        $this->actingAs($user)->get(route('players.edit', $player))
            ->assertOk()
            ->assertSeeInOrder(['SUB-8', 'SUB-12']);
    }
}
