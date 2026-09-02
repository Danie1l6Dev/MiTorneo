<?php

namespace Tests\Feature\Tournaments;

use App\Enums\MatchStatus;
use App\Models\Category;
use App\Models\CompetitionPhase;
use App\Models\Group;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MatchResultTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_owner_can_register_a_result_for_a_scheduled_match(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create();
        $home = Team::factory()->for($tournament)->for($category)->create();
        $away = Team::factory()->for($tournament)->for($category)->create();

        $match = TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $tournament->id,
            'category_id' => $category->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'status' => MatchStatus::Scheduled,
        ]);

        $this->actingAs($user)
            ->patch(route('matches.result.update', $match), ['home_score' => 3, 'away_score' => 1])
            ->assertRedirect(route('phases.show', $phase));

        $match->refresh();

        $this->assertSame(3, $match->home_score);
        $this->assertSame(1, $match->away_score);
        $this->assertSame(MatchStatus::Finished, $match->status);
    }

    public function test_a_registered_result_can_be_edited_afterwards(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create();
        $home = Team::factory()->for($tournament)->for($category)->create();
        $away = Team::factory()->for($tournament)->for($category)->create();

        $match = TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $tournament->id,
            'category_id' => $category->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'status' => MatchStatus::Scheduled,
        ]);

        $this->actingAs($user)
            ->patch(route('matches.result.update', $match), ['home_score' => 3, 'away_score' => 1]);

        $this->actingAs($user)
            ->patch(route('matches.result.update', $match), ['home_score' => 2, 'away_score' => 2])
            ->assertRedirect(route('phases.show', $phase));

        $match->refresh();

        $this->assertSame(2, $match->home_score);
        $this->assertSame(2, $match->away_score);
        $this->assertSame(MatchStatus::Finished, $match->status);
    }

    public function test_negative_scores_are_rejected(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create();
        $home = Team::factory()->for($tournament)->for($category)->create();
        $away = Team::factory()->for($tournament)->for($category)->create();

        $match = TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $tournament->id,
            'category_id' => $category->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
        ]);

        $this->actingAs($user)
            ->patch(route('matches.result.update', $match), ['home_score' => -1, 'away_score' => 0])
            ->assertSessionHasErrors('home_score');

        $this->assertSame(MatchStatus::Scheduled, $match->fresh()->status);
    }

    public function test_non_integer_scores_are_rejected(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create();
        $home = Team::factory()->for($tournament)->for($category)->create();
        $away = Team::factory()->for($tournament)->for($category)->create();

        $match = TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $tournament->id,
            'category_id' => $category->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
        ]);

        $this->actingAs($user)
            ->patch(route('matches.result.update', $match), ['home_score' => '1.5', 'away_score' => 0])
            ->assertSessionHasErrors('home_score');
    }

    public function test_a_user_cannot_register_a_result_for_another_users_match(): void
    {
        $owner = User::factory()->create();
        $tournament = Tournament::factory()->for($owner)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create();
        $home = Team::factory()->for($tournament)->for($category)->create();
        $away = Team::factory()->for($tournament)->for($category)->create();

        $match = TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $tournament->id,
            'category_id' => $category->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
        ]);

        $intruder = User::factory()->create();

        $this->actingAs($intruder)
            ->patch(route('matches.result.update', $match), ['home_score' => 3, 'away_score' => 1])
            ->assertForbidden();

        $this->assertSame(MatchStatus::Scheduled, $match->fresh()->status);
    }

    public function test_the_standings_table_reflects_finished_matches_for_a_category_without_groups(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false, 'name' => 'Juvenil']);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['type' => 'league']);
        $home = Team::factory()->for($tournament)->for($category)->create(['name' => 'Equipo Local']);
        $away = Team::factory()->for($tournament)->for($category)->create(['name' => 'Equipo Visitante']);

        $match = TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $tournament->id,
            'category_id' => $category->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
        ]);

        $this->actingAs($user)
            ->patch(route('matches.result.update', $match), ['home_score' => 2, 'away_score' => 0]);

        $response = $this->actingAs($user)->get(route('phases.show', $phase));

        $response->assertOk()
            ->assertSeeText('Tabla de posiciones')
            ->assertSeeText('Juvenil')
            ->assertSeeText('Equipo Local')
            ->assertSeeText('Equipo Visitante');
    }

    public function test_a_category_with_groups_shows_one_standings_table_per_group(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->usingGroups()->create();
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['type' => 'league']);
        $groupA = Group::factory()->for($tournament)->for($category)->create(['name' => 'Grupo A']);
        $groupB = Group::factory()->for($tournament)->for($category)->create(['name' => 'Grupo B']);
        Team::factory()->for($tournament)->for($category)->for($groupA)->count(2)->create();
        Team::factory()->for($tournament)->for($category)->for($groupB)->count(2)->create();

        $response = $this->actingAs($user)->get(route('phases.show', $phase));

        $response->assertOk()
            ->assertSeeText('Grupo A')
            ->assertSeeText('Grupo B');
    }

    public function test_a_scheduled_match_does_not_affect_the_standings_table(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['type' => 'league']);
        $home = Team::factory()->for($tournament)->for($category)->create();
        $away = Team::factory()->for($tournament)->for($category)->create();

        TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $tournament->id,
            'category_id' => $category->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'status' => MatchStatus::Scheduled,
        ]);

        $response = $this->actingAs($user)->get(route('phases.show', $phase));

        $response->assertOk();

        $standings = $response->viewData('standings');
        $this->assertSame(0, $standings[0]['rows'][0]['played']);
        $this->assertSame(0, $standings[0]['rows'][0]['points']);
    }
}
