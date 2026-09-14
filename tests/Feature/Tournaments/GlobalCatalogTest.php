<?php

namespace Tests\Feature\Tournaments;

use App\Models\Category;
use App\Models\Club;
use App\Models\Group;
use App\Models\Player;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The global (per-organizer) catalog introduced in
 * docs/plan-reestructuracion/01-clubes-equipos-categorias-globales.md --
 * Category/Club/Team no longer need a Tournament to exist.
 */
class GlobalCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_create_a_global_category(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('categories.store'), [
                'name' => 'Baby',
                'status' => 'active',
                'uses_groups' => false,
                'birth_year_from' => 2020,
                'birth_year_to' => 2021,
            ])
            ->assertRedirect();

        $category = Category::query()->where('name', 'BABY')->firstOrFail();
        $this->assertSame($user->id, $category->user_id);
        $this->assertNull($category->tournament_id);
        $this->assertSame(2020, $category->birth_year_from);
    }

    public function test_global_category_names_are_unique_per_organizer_not_globally(): void
    {
        $daniel = User::factory()->create();
        $fulano = User::factory()->create();
        Category::factory()->create(['name' => 'Baby', 'tournament_id' => null, 'user_id' => $daniel->id]);

        // Daniel can't repeat it... (submitted already uppercased so the
        // uniqueness collision is deterministic regardless of the test DB's
        // collation -- see CategoryGroupTeamValidationTest for the same
        // note; NormalizesToUppercase only runs on save, after this rule's
        // own query).
        $this->actingAs($daniel)
            ->post(route('categories.store'), ['name' => 'BABY', 'status' => 'active', 'uses_groups' => false])
            ->assertSessionHasErrors('name');

        // ...but a different organizer can use the same name freely.
        $this->actingAs($fulano)
            ->post(route('categories.store'), ['name' => 'Baby', 'status' => 'active', 'uses_groups' => false])
            ->assertRedirect();

        $this->assertSame(2, Category::query()->where('name', 'BABY')->count());
    }

    public function test_a_user_cannot_view_another_organizers_global_category(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $category = Category::factory()->create(['tournament_id' => null, 'user_id' => $owner->id]);

        $this->actingAs($other)->get(route('categories.show', $category))->assertForbidden();
    }

    public function test_a_user_can_create_a_club(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('clubs.store'), ['name' => 'Nilmar'])
            ->assertRedirect();

        $club = Club::query()->where('name', 'NILMAR')->firstOrFail();
        $this->assertSame($user->id, $club->user_id);
    }

    public function test_club_names_are_unique_per_organizer_not_globally(): void
    {
        $daniel = User::factory()->create();
        $fulano = User::factory()->create();
        Club::factory()->for($daniel)->create(['name' => 'Nilmar']);

        // Submitted already uppercased so the collision is deterministic
        // regardless of the test DB's collation -- see
        // CategoryGroupTeamValidationTest for the same note.
        $this->actingAs($daniel)
            ->post(route('clubs.store'), ['name' => 'NILMAR'])
            ->assertSessionHasErrors('name');

        $this->actingAs($fulano)
            ->post(route('clubs.store'), ['name' => 'Nilmar'])
            ->assertRedirect();
    }

    public function test_creating_a_club_can_also_create_an_initial_team_for_a_grouples_category(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'uses_groups' => false]);

        $this->actingAs($user)
            ->post(route('clubs.store'), [
                'name' => 'Nilmar',
                'category_ids' => [$category->id],
            ])
            ->assertRedirect();

        $club = Club::query()->where('name', 'NILMAR')->firstOrFail();
        $team = Team::query()->where('club_id', $club->id)->where('category_id', $category->id)->firstOrFail();
        $this->assertNull($team->group_id);
        $this->assertSame('NILMAR', $team->name);
    }

    public function test_creating_a_club_with_group_selections_creates_one_team_per_selected_group(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'uses_groups' => true]);
        $groupA = Group::factory()->for($category)->create(['name' => 'Grupo A', 'tournament_id' => null]);
        $groupB = Group::factory()->for($category)->create(['name' => 'Grupo B', 'tournament_id' => null]);

        $this->actingAs($user)
            ->post(route('clubs.store'), [
                'name' => 'Nilmar',
                'group_selections' => [$category->id => [$groupA->id, $groupB->id]],
            ])
            ->assertRedirect();

        $club = Club::query()->where('name', 'NILMAR')->firstOrFail();
        $this->assertSame(2, Team::query()->where('club_id', $club->id)->count());
        $this->assertTrue(Team::query()->where('club_id', $club->id)->where('group_id', $groupA->id)->exists());
        $this->assertTrue(Team::query()->where('club_id', $club->id)->where('group_id', $groupB->id)->exists());
    }

    public function test_cannot_select_a_category_belonging_to_another_organizer_when_creating_a_club(): void
    {
        $user = User::factory()->create();
        $othersCategory = Category::factory()->create(['tournament_id' => null]);

        $this->actingAs($user)
            ->post(route('clubs.store'), [
                'name' => 'Nilmar',
                'category_ids' => [$othersCategory->id],
            ])
            ->assertSessionHasErrors('category_ids.0');

        $this->assertSame(0, Club::query()->where('name', 'Nilmar')->count());
    }

    public function test_a_user_cannot_view_another_organizers_club(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $club = Club::factory()->for($owner)->create();

        $this->actingAs($other)->get(route('clubs.show', $club))->assertForbidden();
    }

    public function test_a_plantel_can_only_be_created_in_a_category_the_organizer_owns(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create();
        $someoneElsesCategory = Category::factory()->create(['tournament_id' => null]);

        $this->actingAs($user)
            ->post(route('clubs.teams.store', $club), [
                'category_id' => $someoneElsesCategory->id,
                'name' => 'Plantel A',
            ])
            ->assertSessionHasErrors('category_id');
    }

    public function test_a_user_cannot_create_a_plantel_under_another_organizers_club(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $club = Club::factory()->for($owner)->create();
        // Own category, so the request passes validation -- this test is
        // specifically about the club-ownership policy check, not the
        // category one (already covered above).
        $ownCategory = Category::factory()->create(['tournament_id' => null, 'user_id' => $other->id, 'uses_groups' => false]);

        $this->actingAs($other)
            ->post(route('clubs.teams.store', $club), [
                'category_id' => $ownCategory->id,
                'name' => 'Plantel A',
            ])
            ->assertForbidden();

        $this->assertSame(0, Team::query()->where('club_id', $club->id)->count());
    }

    public function test_a_plantel_requires_a_group_when_its_category_uses_groups(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create();
        $category = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'uses_groups' => true]);

        $this->actingAs($user)
            ->post(route('clubs.teams.store', $club), [
                'category_id' => $category->id,
                'name' => 'Plantel A',
            ])
            ->assertSessionHasErrors('group_id');
    }

    /**
     * Plan decision #8: a club fielding more than one squad in the very
     * same category(+group) is legitimate (one roster doesn't fit every
     * kid) -- they're told apart by name, not blocked as a duplicate.
     */
    public function test_a_club_can_have_two_squads_in_the_same_category_and_group(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create();
        $category = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'uses_groups' => false]);

        $this->actingAs($user)
            ->post(route('clubs.teams.store', $club), ['category_id' => $category->id, 'name' => 'Plantel A'])
            ->assertRedirect(route('clubs.show', $club));

        $this->actingAs($user)
            ->post(route('clubs.teams.store', $club), ['category_id' => $category->id, 'name' => 'Plantel B'])
            ->assertRedirect(route('clubs.show', $club));

        $this->assertSame(2, Team::query()->where('club_id', $club->id)->where('category_id', $category->id)->count());
    }

    public function test_a_plantel_name_must_be_unique_within_the_same_club_category_and_group(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create();
        $category = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'uses_groups' => false]);
        Team::factory()->create([
            'club_id' => $club->id,
            'category_id' => $category->id,
            'tournament_id' => null,
            'group_id' => null,
            'name' => 'Plantel A',
        ]);

        // Submitted already uppercased so the collision is deterministic
        // regardless of the test DB's collation -- see
        // CategoryGroupTeamValidationTest for the same note.
        $this->actingAs($user)
            ->post(route('clubs.teams.store', $club), ['category_id' => $category->id, 'name' => 'PLANTEL A'])
            ->assertSessionHasErrors('name');
    }

    public function test_two_different_clubs_can_use_the_same_squad_name_in_the_same_category(): void
    {
        $user = User::factory()->create();
        $clubOne = Club::factory()->for($user)->create();
        $clubTwo = Club::factory()->for($user)->create();
        $category = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'uses_groups' => false]);

        $this->actingAs($user)
            ->post(route('clubs.teams.store', $clubOne), ['category_id' => $category->id, 'name' => 'A'])
            ->assertRedirect();

        $this->actingAs($user)
            ->post(route('clubs.teams.store', $clubTwo), ['category_id' => $category->id, 'name' => 'A'])
            ->assertRedirect();

        $this->assertSame(2, Team::query()->where('category_id', $category->id)->count());
    }

    public function test_a_global_teams_show_page_lists_players_linked_via_player_team(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create();
        $category = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id]);
        $team = Team::factory()->create([
            'club_id' => $club->id,
            'category_id' => $category->id,
            'tournament_id' => null,
            'group_id' => null,
        ]);
        // players.team_id is still required today (that column only
        // becomes optional once the "Jugadores" phase of this plan lands),
        // so this player also has some other (legacy) team -- what matters
        // here is that showing THIS global team's roster picks them up via
        // player_team, not team_id.
        $player = Player::factory()->create();
        $team->globalPlayers()->attach($player->id, ['jersey_number' => 9]);

        $this->actingAs($user)
            ->get(route('teams.show', $team))
            ->assertOk()
            ->assertSee($player->full_name);
    }

    public function test_legacy_group_creation_still_works_unchanged(): void
    {
        // Regression guard: policies were rewired to ownerId() -- make sure
        // the untouched legacy per-tournament flow still works exactly as
        // before.
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->usingGroups()->create();

        $this->actingAs($user)
            ->post(route('categories.groups.store', $category), ['name' => 'Grupo A'])
            ->assertRedirect();

        $this->assertSame(1, Group::query()->where('category_id', $category->id)->count());
    }

    /**
     * Powers the ⚠ warning icon in the Clubes views (index, club show) --
     * see docs/plan-reestructuracion/01-clubes-equipos-categorias-globales.md
     * (T01-11/T01-27).
     */
    public function test_ids_with_incomplete_players_finds_both_direct_and_pivot_links(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create();
        $category = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id]);

        $teamMissingDirect = Team::factory()->create(['club_id' => $club->id, 'category_id' => $category->id, 'tournament_id' => null, 'group_id' => null]);
        Player::factory()->create(['team_id' => $teamMissingDirect->id, 'birth_date' => null]);

        $teamMissingViaPivot = Team::factory()->create(['club_id' => $club->id, 'category_id' => $category->id, 'tournament_id' => null, 'group_id' => null]);
        $otherTeam = Team::factory()->create(['club_id' => $club->id, 'category_id' => $category->id, 'tournament_id' => null, 'group_id' => null]);
        $playerWithoutDate = Player::factory()->create(['team_id' => $otherTeam->id, 'birth_date' => null]);
        $playerWithoutDate->teams()->attach($teamMissingViaPivot->id);

        $completeTeam = Team::factory()->create(['club_id' => $club->id, 'category_id' => $category->id, 'tournament_id' => null, 'group_id' => null]);
        Player::factory()->create(['team_id' => $completeTeam->id, 'birth_date' => '2012-01-01']);

        $emptyTeam = Team::factory()->create(['club_id' => $club->id, 'category_id' => $category->id, 'tournament_id' => null, 'group_id' => null]);

        $ids = Team::idsWithIncompletePlayers([
            $teamMissingDirect->id, $teamMissingViaPivot->id, $otherTeam->id, $completeTeam->id, $emptyTeam->id,
        ]);

        $this->assertContains($teamMissingDirect->id, $ids);
        $this->assertContains($teamMissingViaPivot->id, $ids);
        $this->assertContains($otherTeam->id, $ids); // this player's own direct team also lacks a birth_date
        $this->assertNotContains($completeTeam->id, $ids);
        $this->assertNotContains($emptyTeam->id, $ids);
    }

    public function test_the_club_page_offers_a_delete_button_for_each_plantel(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create();
        $category = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'uses_groups' => false]);
        $team = Team::factory()->create(['club_id' => $club->id, 'category_id' => $category->id, 'tournament_id' => null, 'group_id' => null, 'name' => 'Plantel A']);

        $response = $this->actingAs($user)->get(route('clubs.show', $club));

        $response->assertOk()
            ->assertSee('Eliminar plantel')
            ->assertSee(route('teams.destroy', $team), false);
    }

    public function test_deleting_a_plantel_from_the_club_page_also_removes_its_players(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create();
        $category = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'uses_groups' => false]);
        $team = Team::factory()->create(['club_id' => $club->id, 'category_id' => $category->id, 'tournament_id' => null, 'group_id' => null]);
        $player = Player::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user)
            ->delete(route('teams.destroy', $team))
            ->assertRedirect(route('clubs.show', $club));

        $this->assertDatabaseMissing('teams', ['id' => $team->id]);
        $this->assertDatabaseMissing('players', ['id' => $player->id]);
    }
}
