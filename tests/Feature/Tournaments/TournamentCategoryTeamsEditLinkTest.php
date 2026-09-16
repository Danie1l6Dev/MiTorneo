<?php

namespace Tests\Feature\Tournaments;

use App\Models\Category;
use App\Models\Group;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * teams/edit.blade.php's own "Cancelar" and delete flows always land back on
 * categories.show($team->category) -- the global catalog page, the one
 * place a plantel's own fields (name/short_name) belong regardless of which
 * tournament it's entered in. That's a fine "home" to return to from the
 * catalog's own team list (still editable) or a group's roster, but from a
 * tournament's own "Equipos inscritos" list (tournaments.categories.show)
 * it silently drops the organizer out of the tournament they were just
 * looking at and into the catalog instead -- so that list hides the pencil
 * button entirely (x-ui.team-row's $editable prop) rather than editing from
 * there.
 */
class TournamentCategoryTeamsEditLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_tournament_categorys_team_list_has_no_edit_link(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $team = Team::factory()->for($tournament)->for($category)->create();

        $this->actingAs($user)->get(route('tournaments.categories.show', [$tournament, $category]))
            ->assertOk()
            ->assertDontSee(route('teams.edit', $team), false);
    }

    public function test_the_tournament_categorys_team_list_has_no_edit_link_when_grouped(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => true]);
        $group = Group::factory()->for($category)->create();
        $team = Team::factory()->for($tournament)->for($category)->create(['group_id' => $group->id]);

        $this->actingAs($user)->get(route('tournaments.categories.show', [$tournament, $category]))
            ->assertOk()
            ->assertDontSee(route('teams.edit', $team), false);
    }

    public function test_the_catalog_categorys_team_list_still_has_the_edit_link(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $team = Team::factory()->for($tournament)->for($category)->create();

        $this->actingAs($user)->get(route('categories.show', $category))
            ->assertOk()
            ->assertSee(route('teams.edit', $team), false);
    }
}
