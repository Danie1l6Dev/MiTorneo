<?php

namespace Tests\Feature\Tournaments;

use App\Models\Category;
use App\Models\Club;
use App\Models\CompetitionPhase;
use App\Models\Group;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Inscripción" of a tournament from the global catalog -- see
 * docs/plan-reestructuracion/01-clubes-equipos-categorias-globales.md
 * (T01-24/T01-25) and docs/plan-reestructuracion/02-unificacion-categorias-torneo.md
 * (T02-03, which taught phases/matches to draw their roster from the
 * tournament_category/tournament_team pivots).
 */
class TournamentCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_add_a_global_category_to_a_tournament(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id]);

        $this->actingAs($user)
            ->post(route('tournaments.global-categories.store', $tournament), [
                'category_ids' => [$category->id],
            ])
            ->assertRedirect(route('tournaments.show', $tournament));

        $this->assertTrue($tournament->globalCategories()->whereKey($category->id)->exists());
    }

    public function test_the_create_form_only_lists_categories_not_yet_added(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $alreadyAdded = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'name' => 'Baby']);
        $available = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'name' => 'Juvenil']);
        $tournament->globalCategories()->attach($alreadyAdded->id);

        $response = $this->actingAs($user)->get(route('tournaments.global-categories.create', $tournament));

        $response->assertOk()->assertSee('JUVENIL')->assertDontSee('Baby');
    }

    public function test_the_empty_state_differs_between_no_categories_yet_and_all_already_added(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('tournaments.global-categories.create', $tournament))
            ->assertOk()
            ->assertSee('Todavía no creaste ninguna categoría')
            ->assertSee('Crear categoría');

        $onlyCategory = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id]);
        $tournament->globalCategories()->attach($onlyCategory->id);

        $this->actingAs($user)
            ->get(route('tournaments.global-categories.create', $tournament))
            ->assertOk()
            ->assertSee('Ya agregaste todas tus categorías actuales a este torneo')
            ->assertDontSee('Crear categoría');
    }

    public function test_cannot_add_a_category_belonging_to_another_organizer(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $othersCategory = Category::factory()->create(['tournament_id' => null]);

        $this->actingAs($user)
            ->post(route('tournaments.global-categories.store', $tournament), [
                'category_ids' => [$othersCategory->id],
            ])
            ->assertSessionHasErrors('category_ids.0');

        $this->assertFalse($tournament->globalCategories()->whereKey($othersCategory->id)->exists());
    }

    public function test_a_user_cannot_add_a_category_to_another_organizers_tournament(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $tournament = Tournament::factory()->for($owner)->create();
        $category = Category::factory()->create(['tournament_id' => null, 'user_id' => $other->id]);

        $this->actingAs($other)
            ->post(route('tournaments.global-categories.store', $tournament), [
                'category_ids' => [$category->id],
            ])
            ->assertForbidden();
    }

    public function test_removing_a_category_also_detaches_its_teams(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $club = Club::factory()->for($user)->create();
        $category = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'uses_groups' => false]);
        $team = Team::factory()->create(['club_id' => $club->id, 'category_id' => $category->id, 'tournament_id' => null, 'group_id' => null]);
        $tournament->globalCategories()->attach($category->id);
        $tournament->globalTeams()->attach($team->id);

        $this->actingAs($user)
            ->delete(route('tournaments.global-categories.destroy', [$tournament, $category]))
            ->assertRedirect();

        $this->assertFalse($tournament->globalCategories()->whereKey($category->id)->exists());
        $this->assertFalse($tournament->globalTeams()->whereKey($team->id)->exists());
    }

    public function test_a_user_can_select_which_teams_of_a_category_join_the_tournament(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $club = Club::factory()->for($user)->create();
        $category = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'uses_groups' => false]);
        $teamOne = Team::factory()->create(['club_id' => $club->id, 'category_id' => $category->id, 'tournament_id' => null, 'group_id' => null]);
        $teamTwo = Team::factory()->create(['club_id' => $club->id, 'category_id' => $category->id, 'tournament_id' => null, 'group_id' => null]);
        $tournament->globalCategories()->attach($category->id);

        $this->actingAs($user)
            ->put(route('tournaments.global-categories.teams.update', [$tournament, $category]), [
                'team_ids' => [$teamOne->id],
            ])
            ->assertRedirect(route('tournaments.show', $tournament));

        $this->assertTrue($tournament->globalTeams()->whereKey($teamOne->id)->exists());
        $this->assertFalse($tournament->globalTeams()->whereKey($teamTwo->id)->exists());
    }

    public function test_updating_teams_replaces_the_previous_selection_for_that_category_only(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $club = Club::factory()->for($user)->create();

        $categoryA = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'uses_groups' => false, 'name' => 'A']);
        $teamA1 = Team::factory()->create(['club_id' => $club->id, 'category_id' => $categoryA->id, 'tournament_id' => null, 'group_id' => null]);
        $teamA2 = Team::factory()->create(['club_id' => $club->id, 'category_id' => $categoryA->id, 'tournament_id' => null, 'group_id' => null]);

        $categoryB = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'uses_groups' => false, 'name' => 'B']);
        $teamB1 = Team::factory()->create(['club_id' => $club->id, 'category_id' => $categoryB->id, 'tournament_id' => null, 'group_id' => null]);

        $tournament->globalCategories()->attach([$categoryA->id, $categoryB->id]);
        $tournament->globalTeams()->attach([$teamA1->id, $teamB1->id]);

        $this->actingAs($user)
            ->put(route('tournaments.global-categories.teams.update', [$tournament, $categoryA]), [
                'team_ids' => [$teamA2->id],
            ])
            ->assertRedirect();

        $this->assertFalse($tournament->globalTeams()->whereKey($teamA1->id)->exists());
        $this->assertTrue($tournament->globalTeams()->whereKey($teamA2->id)->exists());
        // Category B's own selection is untouched.
        $this->assertTrue($tournament->globalTeams()->whereKey($teamB1->id)->exists());
    }

    public function test_cannot_select_a_team_that_does_not_belong_to_the_category(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $club = Club::factory()->for($user)->create();
        $category = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'uses_groups' => false]);
        $otherCategory = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'uses_groups' => false]);
        $teamFromOtherCategory = Team::factory()->create(['club_id' => $club->id, 'category_id' => $otherCategory->id, 'tournament_id' => null, 'group_id' => null]);
        $tournament->globalCategories()->attach($category->id);

        $this->actingAs($user)
            ->put(route('tournaments.global-categories.teams.update', [$tournament, $category]), [
                'team_ids' => [$teamFromOtherCategory->id],
            ])
            ->assertSessionHasErrors('team_ids.0');
    }

    public function test_cannot_manage_teams_for_a_category_not_added_to_the_tournament(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('tournaments.global-categories.teams.edit', [$tournament, $category]))
            ->assertNotFound();
    }

    public function test_teams_are_grouped_and_shown_with_club_and_plantel_name(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $club = Club::factory()->for($user)->create(['name' => 'Nilmar']);
        $category = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'uses_groups' => true]);
        $groupA = Group::factory()->for($category)->create(['name' => 'Grupo A', 'tournament_id' => null]);
        $groupB = Group::factory()->for($category)->create(['name' => 'Grupo B', 'tournament_id' => null]);
        Team::factory()->create([
            'club_id' => $club->id,
            'category_id' => $category->id,
            'tournament_id' => null,
            'group_id' => $groupA->id,
            'name' => 'Nilmar (A)',
        ]);
        Team::factory()->create([
            'club_id' => $club->id,
            'category_id' => $category->id,
            'tournament_id' => null,
            'group_id' => $groupB->id,
            'name' => 'Nilmar (B)',
        ]);
        $tournament->globalCategories()->attach($category->id);

        $response = $this->actingAs($user)->get(route('tournaments.global-categories.teams.edit', [$tournament, $category]));

        $response->assertOk()->assertSee('GRUPO A')->assertSee('NILMAR (A)')->assertSee('NILMAR (B)');
    }

    public function test_a_user_cannot_manage_another_organizers_tournament_categories(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $tournament = Tournament::factory()->for($owner)->create();
        $category = Category::factory()->create(['tournament_id' => null, 'user_id' => $owner->id]);
        $tournament->globalCategories()->attach($category->id);

        $this->actingAs($other)
            ->get(route('tournaments.global-categories.teams.edit', [$tournament, $category]))
            ->assertForbidden();

        $this->actingAs($other)
            ->delete(route('tournaments.global-categories.destroy', [$tournament, $category]))
            ->assertForbidden();
    }

    public function test_the_tournament_show_page_lists_global_categories_with_team_counts(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $club = Club::factory()->for($user)->create();
        $category = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'uses_groups' => false, 'name' => 'Juvenil']);
        $team = Team::factory()->create(['club_id' => $club->id, 'category_id' => $category->id, 'tournament_id' => null, 'group_id' => null]);
        $tournament->globalCategories()->attach($category->id);
        $tournament->globalTeams()->attach($team->id);

        $this->actingAs($user)
            ->get(route('tournaments.show', $tournament))
            ->assertOk()
            ->assertSee('JUVENIL')
            ->assertSee('1 plantel inscrito');
    }

    /**
     * T02-10: once a category has a phase in this tournament, its
     * bracket/schedule was already drawn from the exact team list inscribed
     * at that moment -- changing the roster afterward would leave matches
     * referencing a team no longer "in" the tournament (or vice versa).
     */
    public function test_cannot_update_teams_for_a_category_that_already_has_a_phase_in_this_tournament(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $club = Club::factory()->for($user)->create();
        $category = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'uses_groups' => false]);
        $teamOne = Team::factory()->create(['club_id' => $club->id, 'category_id' => $category->id, 'tournament_id' => null, 'group_id' => null]);
        $teamTwo = Team::factory()->create(['club_id' => $club->id, 'category_id' => $category->id, 'tournament_id' => null, 'group_id' => null]);
        $tournament->globalCategories()->attach($category->id);
        $tournament->globalTeams()->attach($teamOne->id);
        CompetitionPhase::factory()->for($category)->for($tournament)->create();

        $this->actingAs($user)
            ->put(route('tournaments.global-categories.teams.update', [$tournament, $category]), [
                'team_ids' => [$teamTwo->id],
            ])
            ->assertRedirect();

        $this->assertTrue($tournament->globalTeams()->whereKey($teamOne->id)->exists());
        $this->assertFalse($tournament->globalTeams()->whereKey($teamTwo->id)->exists());
    }

    public function test_cannot_remove_a_category_from_a_tournament_that_already_has_a_phase(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id]);
        $tournament->globalCategories()->attach($category->id);
        CompetitionPhase::factory()->for($category)->for($tournament)->create();

        $this->actingAs($user)
            ->delete(route('tournaments.global-categories.destroy', [$tournament, $category]))
            ->assertRedirect();

        $this->assertTrue($tournament->globalCategories()->whereKey($category->id)->exists());
    }

    public function test_the_teams_edit_page_shows_a_read_only_locked_state_once_a_phase_exists(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $club = Club::factory()->for($user)->create();
        $category = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'uses_groups' => false]);
        $team = Team::factory()->create(['club_id' => $club->id, 'category_id' => $category->id, 'tournament_id' => null, 'group_id' => null]);
        $tournament->globalCategories()->attach($category->id);
        $tournament->globalTeams()->attach($team->id);
        CompetitionPhase::factory()->for($category)->for($tournament)->create();

        $response = $this->actingAs($user)->get(route('tournaments.global-categories.teams.edit', [$tournament, $category]));

        $response->assertOk()
            ->assertSee('Planteles bloqueados')
            ->assertDontSee('Guardar planteles');
    }

    public function test_can_still_update_teams_for_a_category_with_no_phase_yet(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $club = Club::factory()->for($user)->create();
        $category = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'uses_groups' => false]);
        $team = Team::factory()->create(['club_id' => $club->id, 'category_id' => $category->id, 'tournament_id' => null, 'group_id' => null]);
        $tournament->globalCategories()->attach($category->id);

        $this->actingAs($user)
            ->put(route('tournaments.global-categories.teams.update', [$tournament, $category]), [
                'team_ids' => [$team->id],
            ])
            ->assertRedirect();

        $this->assertTrue($tournament->globalTeams()->whereKey($team->id)->exists());
    }
}
