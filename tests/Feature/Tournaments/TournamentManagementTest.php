<?php

namespace Tests\Feature\Tournaments;

use App\Models\Category;
use App\Models\CompetitionPhase;
use App\Models\Group;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TournamentManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $response = $this->get(route('tournaments.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_a_user_can_build_the_full_competition_hierarchy(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->post(route('tournaments.store'), [
            'name' => 'Campeonato Municipal 2026',
            'season' => '2026',
            'status' => 'active',
        ])->assertRedirect();

        $tournament = Tournament::query()->firstWhere('name', 'Campeonato Municipal 2026');
        $this->assertNotNull($tournament);
        $this->assertSame($user->id, $tournament->user_id);

        $this->post(route('tournaments.categories.store', $tournament), [
            'name' => 'Teterito',
            'status' => 'active',
            'uses_groups' => '1',
            'order' => 0,
        ])->assertRedirect();

        $category = $tournament->categories()->firstWhere('name', 'Teterito');
        $this->assertNotNull($category);
        $this->assertSame($tournament->id, $category->tournament_id);
        $this->assertTrue($category->uses_groups);

        $this->post(route('categories.phases.store', $category), [
            'name' => 'Liga',
            'type' => 'league',
            'order' => 0,
        ])->assertRedirect();

        $phase = $category->competitionPhases()->firstWhere('name', 'Liga');
        $this->assertNotNull($phase);
        $this->assertSame($tournament->id, $phase->tournament_id);

        $this->post(route('categories.groups.store', $category), [
            'name' => 'Grupo A',
            'order' => 0,
        ])->assertRedirect();

        $group = $category->groups()->firstWhere('name', 'Grupo A');
        $this->assertNotNull($group);
        $this->assertSame($tournament->id, $group->tournament_id);

        $this->post(route('categories.teams.store', $category), [
            'name' => 'Equipo 1',
            'group_id' => $group->id,
        ])->assertRedirect();
        $this->post(route('categories.teams.store', $category), [
            'name' => 'Equipo 2',
            'group_id' => $group->id,
        ])->assertRedirect();

        $teamOne = $category->teams()->firstWhere('name', 'Equipo 1');
        $teamTwo = $category->teams()->firstWhere('name', 'Equipo 2');
        $this->assertNotNull($teamOne);
        $this->assertNotNull($teamTwo);
        $this->assertSame($group->id, $teamOne->group_id);
        $this->assertSame($group->id, $teamTwo->group_id);

        $this->post(route('phases.matches.store', $phase), [
            'group_id' => $group->id,
            'home_team_id' => $teamOne->id,
            'away_team_id' => $teamTwo->id,
            'status' => 'scheduled',
        ])->assertRedirect();

        $match = $phase->matches()->first();
        $this->assertNotNull($match);
        $this->assertSame($tournament->id, $match->tournament_id);
        $this->assertSame($teamOne->id, $match->home_team_id);
        $this->assertSame($teamTwo->id, $match->away_team_id);
    }

    public function test_all_pages_in_the_hierarchy_render_successfully_for_their_owner(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->usingGroups()->create();
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create();
        $group = Group::factory()->for($tournament)->for($category)->create();
        $teamOne = Team::factory()->for($tournament)->for($category)->for($group)->create();
        $teamTwo = Team::factory()->for($tournament)->for($category)->for($group)->create();

        $match = TournamentMatch::forceCreate([
            'tournament_id' => $tournament->id,
            'competition_phase_id' => $phase->id,
            'group_id' => $group->id,
            'home_team_id' => $teamOne->id,
            'away_team_id' => $teamTwo->id,
            'status' => 'scheduled',
        ]);

        $this->actingAs($user);

        $this->get(route('tournaments.index'))->assertOk();
        $this->get(route('tournaments.create'))->assertOk();
        $this->get(route('tournaments.show', $tournament))->assertOk();
        $this->get(route('tournaments.edit', $tournament))->assertOk();

        $this->get(route('tournaments.categories.create', $tournament))->assertOk();
        $this->get(route('categories.show', $category))->assertOk();
        $this->get(route('categories.edit', $category))->assertOk();

        $this->get(route('categories.phases.create', $category))->assertOk();
        $this->get(route('phases.show', $phase))->assertOk();
        $this->get(route('phases.edit', $phase))->assertOk();

        $this->get(route('categories.groups.create', $category))->assertOk();
        $this->get(route('groups.show', $group))->assertOk();
        $this->get(route('groups.edit', $group))->assertOk();

        $this->get(route('categories.teams.create', $category))->assertOk();
        $this->get(route('teams.edit', $teamOne))->assertOk();

        $this->get(route('phases.matches.create', $phase))->assertOk();
        $this->get(route('matches.edit', $match))->assertOk();
    }

    public function test_a_user_cannot_create_a_match_with_a_team_from_another_category(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create();
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create();
        $teamInCategory = Team::factory()->for($tournament)->for($category)->create();
        $teamInOtherCategory = Team::factory()->for($tournament)->create();

        $this->actingAs($user)->post(route('phases.matches.store', $phase), [
            'home_team_id' => $teamInCategory->id,
            'away_team_id' => $teamInOtherCategory->id,
            'status' => 'scheduled',
        ])->assertSessionHasErrors('away_team_id');

        $this->assertSame(0, $phase->matches()->count());
    }

    public function test_a_user_cannot_access_another_users_tournament(): void
    {
        $owner = User::factory()->create();
        $tournament = Tournament::factory()->for($owner)->create();

        $intruder = User::factory()->create();
        $this->actingAs($intruder);

        $this->get(route('tournaments.show', $tournament))->assertForbidden();
        $this->get(route('tournaments.edit', $tournament))->assertForbidden();
        $this->put(route('tournaments.update', $tournament), [
            'name' => 'Hackeado',
            'status' => 'draft',
        ])->assertForbidden();
        $this->delete(route('tournaments.destroy', $tournament))->assertForbidden();

        $this->assertDatabaseHas('tournaments', ['id' => $tournament->id, 'name' => $tournament->name]);
    }

    public function test_a_user_cannot_access_descendants_of_another_users_tournament(): void
    {
        $owner = User::factory()->create();
        $tournament = Tournament::factory()->for($owner)->create();
        $category = Category::factory()->for($tournament)->usingGroups()->create();
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create();
        $group = Group::factory()->for($tournament)->for($category)->create();
        $teamOne = Team::factory()->for($tournament)->for($category)->for($group)->create();
        $teamTwo = Team::factory()->for($tournament)->for($category)->for($group)->create();

        $match = TournamentMatch::forceCreate([
            'tournament_id' => $tournament->id,
            'competition_phase_id' => $phase->id,
            'group_id' => $group->id,
            'home_team_id' => $teamOne->id,
            'away_team_id' => $teamTwo->id,
            'status' => 'scheduled',
        ]);

        $intruder = User::factory()->create();
        $this->actingAs($intruder);

        $this->get(route('categories.show', $category))->assertForbidden();
        $this->get(route('phases.show', $phase))->assertForbidden();
        $this->get(route('groups.show', $group))->assertForbidden();
        $this->get(route('teams.edit', $teamOne))->assertForbidden();
        $this->get(route('matches.edit', $match))->assertForbidden();
        $this->delete(route('teams.destroy', $teamOne))->assertForbidden();
        $this->delete(route('matches.destroy', $match))->assertForbidden();
        $this->patch(route('groups.teams.update', $group), ['team_ids' => []])->assertForbidden();
        $this->patch(route('categories.toggle-status', $category))->assertForbidden();

        $this->assertDatabaseHas('teams', ['id' => $teamOne->id]);
        $this->assertDatabaseHas('matches', ['id' => $match->id]);
    }

    public function test_deleting_a_tournament_cascades_to_its_descendants(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create();
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create();
        $team = Team::factory()->for($tournament)->for($category)->create();

        $this->actingAs($user)
            ->delete(route('tournaments.destroy', $tournament))
            ->assertRedirect(route('tournaments.index'));

        $this->assertDatabaseMissing('tournaments', ['id' => $tournament->id]);
        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
        $this->assertDatabaseMissing('competition_phases', ['id' => $phase->id]);
        $this->assertDatabaseMissing('teams', ['id' => $team->id]);
    }
}
