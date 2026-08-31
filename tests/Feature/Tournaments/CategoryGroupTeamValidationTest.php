<?php

namespace Tests\Feature\Tournaments;

use App\Models\Category;
use App\Models\Group;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryGroupTeamValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_group_names_must_be_unique_within_a_category(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->usingGroups()->create();
        Group::factory()->for($tournament)->for($category)->create(['name' => 'Grupo A']);

        $this->actingAs($user)
            ->post(route('categories.groups.store', $category), ['name' => 'Grupo A'])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, $category->groups()->count());
    }

    public function test_team_names_must_be_unique_within_a_category(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create();
        Team::factory()->for($tournament)->for($category)->create(['name' => 'Real Norte']);

        $this->actingAs($user)
            ->post(route('categories.teams.store', $category), ['name' => 'Real Norte'])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, $category->teams()->count());
    }

    public function test_a_team_requires_a_group_when_the_category_uses_groups(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->usingGroups()->create();

        $this->actingAs($user)
            ->post(route('categories.teams.store', $category), ['name' => 'Equipo 1'])
            ->assertSessionHasErrors('group_id');

        $this->assertSame(0, $category->teams()->count());
    }

    public function test_a_team_cannot_have_a_group_when_the_category_does_not_use_groups(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $otherCategory = Category::factory()->for($tournament)->usingGroups()->create();
        $group = Group::factory()->for($tournament)->for($otherCategory)->create();

        $this->actingAs($user)
            ->post(route('categories.teams.store', $category), [
                'name' => 'Equipo 1',
                'group_id' => $group->id,
            ])
            ->assertSessionHasErrors('group_id');

        $this->assertSame(0, $category->teams()->count());
    }

    public function test_a_team_cannot_be_assigned_to_a_group_from_another_category(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->usingGroups()->create();
        $otherCategory = Category::factory()->for($tournament)->usingGroups()->create();
        $groupInOtherCategory = Group::factory()->for($tournament)->for($otherCategory)->create();

        $this->actingAs($user)
            ->post(route('categories.teams.store', $category), [
                'name' => 'Equipo 1',
                'group_id' => $groupInOtherCategory->id,
            ])
            ->assertSessionHasErrors('group_id');

        $this->assertSame(0, $category->teams()->count());
    }

    public function test_uses_groups_cannot_be_disabled_while_teams_are_still_assigned_to_a_group(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->usingGroups()->create();
        $group = Group::factory()->for($tournament)->for($category)->create();
        Team::factory()->for($tournament)->for($category)->for($group)->create();

        $this->actingAs($user)
            ->put(route('categories.update', $category), [
                'name' => $category->name,
                'status' => 'active',
                'uses_groups' => '0',
            ])
            ->assertSessionHasErrors('uses_groups');

        $this->assertTrue($category->fresh()->uses_groups);
    }

    public function test_a_group_with_teams_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->usingGroups()->create();
        $group = Group::factory()->for($tournament)->for($category)->create();
        Team::factory()->for($tournament)->for($category)->for($group)->create();

        $this->actingAs($user)
            ->delete(route('groups.destroy', $group))
            ->assertRedirect();

        $this->assertDatabaseHas('groups', ['id' => $group->id]);
    }

    public function test_an_empty_group_can_be_deleted(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->usingGroups()->create();
        $group = Group::factory()->for($tournament)->for($category)->create();

        $this->actingAs($user)
            ->delete(route('groups.destroy', $group))
            ->assertRedirect(route('categories.show', $category));

        $this->assertDatabaseMissing('groups', ['id' => $group->id]);
    }

    public function test_a_category_with_dependencies_is_not_deleted_without_explicit_confirmation(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create();
        Team::factory()->for($tournament)->for($category)->create();

        $this->actingAs($user)
            ->delete(route('categories.destroy', $category))
            ->assertRedirect();

        $this->assertDatabaseHas('categories', ['id' => $category->id]);

        $this->actingAs($user)
            ->delete(route('categories.destroy', $category), ['force' => '1'])
            ->assertRedirect(route('tournaments.show', $tournament));

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    public function test_a_category_without_dependencies_can_be_deleted_directly(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create();

        $this->actingAs($user)
            ->delete(route('categories.destroy', $category))
            ->assertRedirect(route('tournaments.show', $tournament));

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    public function test_a_user_can_toggle_a_categorys_status(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create();

        $this->actingAs($user)
            ->patch(route('categories.toggle-status', $category))
            ->assertRedirect();

        $this->assertSame('inactive', $category->fresh()->status->value);
    }

    public function test_updating_the_teams_of_a_group_moves_them_from_other_groups_in_the_category(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->usingGroups()->create();
        $groupA = Group::factory()->for($tournament)->for($category)->create(['name' => 'Grupo A']);
        $groupB = Group::factory()->for($tournament)->for($category)->create(['name' => 'Grupo B']);
        $team = Team::factory()->for($tournament)->for($category)->for($groupA)->create();

        $this->actingAs($user)
            ->patch(route('groups.teams.update', $groupB), ['team_ids' => [$team->id]])
            ->assertRedirect();

        $this->assertSame($groupB->id, $team->fresh()->group_id);
    }
}
