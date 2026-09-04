<?php

namespace Tests\Feature\Tournaments;

use App\Enums\CompetitionPhaseType;
use App\Enums\DrawMethod;
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

class PhaseEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private function finishedMatch(CompetitionPhase $phase, Team $home, Team $away, ?Group $group = null): TournamentMatch
    {
        return TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $phase->tournament_id,
            'category_id' => $phase->category_id,
            'group_id' => $group?->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'home_score' => 1,
            'away_score' => 0,
            'status' => MatchStatus::Finished,
        ]);
    }

    public function test_a_category_using_groups_can_only_start_with_a_league(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->usingGroups()->create();

        $response = $this->actingAs($user)->post(route('categories.phases.store', $category), [
            'name' => 'Eliminatoria',
            'type' => CompetitionPhaseType::Knockout->value,
            'draw_method' => DrawMethod::Random->value,
        ]);

        $response->assertSessionHasErrors('type');
        $this->assertDatabaseMissing('competition_phases', ['name' => 'Eliminatoria']);
    }

    public function test_a_category_without_groups_cannot_start_with_a_knockout_when_team_count_is_not_a_power_of_two(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        Team::factory()->for($tournament)->for($category)->count(3)->create();

        $response = $this->actingAs($user)->post(route('categories.phases.store', $category), [
            'name' => 'Eliminatoria',
            'type' => CompetitionPhaseType::Knockout->value,
            'draw_method' => DrawMethod::Random->value,
        ]);

        $response->assertSessionHasErrors('type');
        $this->assertDatabaseMissing('competition_phases', ['name' => 'Eliminatoria']);
    }

    public function test_a_category_without_groups_can_start_with_a_knockout_when_team_count_is_a_power_of_two(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $teams = Team::factory()->for($tournament)->for($category)->count(4)->create();

        $response = $this->actingAs($user)->post(route('categories.phases.store', $category), [
            'name' => 'Eliminatoria',
            'type' => CompetitionPhaseType::Knockout->value,
            'draw_method' => DrawMethod::Random->value,
        ]);

        $phase = CompetitionPhase::where('name', 'Eliminatoria')->firstOrFail();
        $response->assertRedirect(route('phases.show', $phase));

        $this->assertSame(CompetitionPhaseType::Knockout, $phase->type);
        $this->assertSame(1, $phase->order);

        // The bracket is drawn immediately: 2 first-round matches for the 4 teams.
        $this->assertSame(2, $phase->matches()->where('round_number', 1)->count());
        $playingTeamIds = $phase->matches()->where('round_number', 1)->get()
            ->flatMap(fn (TournamentMatch $match): array => [$match->home_team_id, $match->away_team_id])
            ->sort()->values();
        $this->assertSame($teams->pluck('id')->sort()->values()->all(), $playingTeamIds->all());
    }

    public function test_a_category_cannot_get_a_second_first_phase(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        CompetitionPhase::factory()->for($tournament)->for($category)->create(['type' => CompetitionPhaseType::League, 'order' => 1]);

        $this->actingAs($user)->get(route('categories.phases.create', $category))->assertRedirect(route('categories.show', $category));

        $response = $this->actingAs($user)->post(route('categories.phases.store', $category), [
            'name' => 'Otra liga',
            'type' => CompetitionPhaseType::League->value,
        ]);

        $response->assertRedirect(route('categories.show', $category));
        $this->assertDatabaseMissing('competition_phases', ['name' => 'Otra liga']);
    }

    public function test_a_phases_type_cannot_change_once_it_has_matches(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['type' => CompetitionPhaseType::League, 'order' => 1]);
        $teams = Team::factory()->for($tournament)->for($category)->count(2)->create();
        $this->finishedMatch($phase, $teams[0], $teams[1]);

        $response = $this->actingAs($user)->put(route('phases.update', $phase), [
            'name' => $phase->name,
            'type' => CompetitionPhaseType::Knockout->value,
            'draw_method' => DrawMethod::Random->value,
        ]);

        $response->assertSessionHasErrors('type');
        $this->assertSame(CompetitionPhaseType::League, $phase->fresh()->type);
    }

    public function test_a_locked_phases_name_and_order_can_still_be_updated_when_type_is_unchanged(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['type' => CompetitionPhaseType::League, 'order' => 1]);
        $teams = Team::factory()->for($tournament)->for($category)->count(2)->create();
        $this->finishedMatch($phase, $teams[0], $teams[1]);

        $response = $this->actingAs($user)->put(route('phases.update', $phase), [
            'name' => 'Fase de Liga (renombrada)',
            'type' => CompetitionPhaseType::League->value,
            'order' => 5,
        ]);

        $response->assertRedirect(route('phases.show', $phase));
        $phase->refresh();
        $this->assertSame('Fase de Liga (renombrada)', $phase->name);
        $this->assertSame(5, $phase->order);
        $this->assertSame(CompetitionPhaseType::League, $phase->type);
    }

    public function test_semifinal_from_a_single_table_needs_four_qualifiers_not_two(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['type' => CompetitionPhaseType::League, 'order' => 1]);
        $teams = Team::factory()->for($tournament)->for($category)->count(6)->create();

        $this->finishedMatch($phase, $teams[0], $teams[1]);
        $this->finishedMatch($phase, $teams[2], $teams[3]);
        $this->finishedMatch($phase, $teams[4], $teams[5]);

        $response = $this->actingAs($user)->post(route('phases.advance.store', $phase), [
            'name' => 'Semifinales',
            'type' => CompetitionPhaseType::Semifinal->value,
            'draw_method' => DrawMethod::Random->value,
        ]);

        $semifinal = CompetitionPhase::where('name', 'Semifinales')->firstOrFail();
        $response->assertRedirect(route('phases.show', $semifinal));
        $this->assertSame(4, $semifinal->teams()->count());
        $this->assertSame(3, $semifinal->matches()->count());
    }

    public function test_final_from_a_single_table_needs_two_qualifiers(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['type' => CompetitionPhaseType::League, 'order' => 1]);
        $teams = Team::factory()->for($tournament)->for($category)->count(4)->create();

        $this->finishedMatch($phase, $teams[0], $teams[1]);
        $this->finishedMatch($phase, $teams[2], $teams[3]);

        $response = $this->actingAs($user)->post(route('phases.advance.store', $phase), [
            'name' => 'Final',
            'type' => CompetitionPhaseType::Final->value,
            'draw_method' => DrawMethod::Random->value,
        ]);

        $final = CompetitionPhase::where('name', 'Final')->firstOrFail();
        $response->assertRedirect(route('phases.show', $final));
        $this->assertSame(2, $final->teams()->count());
        $this->assertSame(1, $final->matches()->count());
    }

    public function test_semifinal_is_rejected_when_it_cannot_be_split_evenly_across_three_groups(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->usingGroups()->create();
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['type' => CompetitionPhaseType::League, 'order' => 1]);

        $groups = Group::factory()->for($tournament)->for($category)->count(3)->create();

        foreach ($groups as $group) {
            $groupTeams = Team::factory()->for($tournament)->for($category)->for($group)->count(2)->create();
            $this->finishedMatch($phase, $groupTeams[0], $groupTeams[1], $group);
        }

        $response = $this->actingAs($user)->post(route('phases.advance.store', $phase), [
            'name' => 'Semifinales',
            'type' => CompetitionPhaseType::Semifinal->value,
            'draw_method' => DrawMethod::Random->value,
        ]);

        $response->assertSessionHasErrors('qualifiers_per_table');
        $this->assertDatabaseMissing('competition_phases', ['name' => 'Semifinales']);
    }

    /**
     * 4 total qualifiers only splits evenly across 1, 2, or 4 groups -- any
     * other group count (3, 5, 6...) can't produce a semifinal at all.
     */
    public function test_semifinal_is_allowed_with_four_groups_one_qualifier_each(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->usingGroups()->create();
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['type' => CompetitionPhaseType::League, 'order' => 1]);

        $groups = Group::factory()->for($tournament)->for($category)->count(4)->create();

        foreach ($groups as $group) {
            $groupTeams = Team::factory()->for($tournament)->for($category)->for($group)->count(2)->create();
            $this->finishedMatch($phase, $groupTeams[0], $groupTeams[1], $group);
        }

        $response = $this->actingAs($user)->post(route('phases.advance.store', $phase), [
            'name' => 'Semifinales',
            'type' => CompetitionPhaseType::Semifinal->value,
            'draw_method' => DrawMethod::Random->value,
        ]);

        $semifinal = CompetitionPhase::where('name', 'Semifinales')->firstOrFail();
        $response->assertRedirect(route('phases.show', $semifinal));
        $this->assertSame(4, $semifinal->teams()->count());
    }

    /**
     * 2 total qualifiers only splits evenly across 1 or 2 groups -- with 4
     * groups there's no way to take a whole number of qualifiers from each
     * that adds up to exactly 2, so the final can't be created this way.
     */
    public function test_final_is_rejected_with_four_groups(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->usingGroups()->create();
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['type' => CompetitionPhaseType::League, 'order' => 1]);

        $groups = Group::factory()->for($tournament)->for($category)->count(4)->create();

        foreach ($groups as $group) {
            $groupTeams = Team::factory()->for($tournament)->for($category)->for($group)->count(2)->create();
            $this->finishedMatch($phase, $groupTeams[0], $groupTeams[1], $group);
        }

        $response = $this->actingAs($user)->post(route('phases.advance.store', $phase), [
            'name' => 'Final',
            'type' => CompetitionPhaseType::Final->value,
            'draw_method' => DrawMethod::Random->value,
        ]);

        $response->assertSessionHasErrors('qualifiers_per_table');
        $this->assertDatabaseMissing('competition_phases', ['name' => 'Final']);
    }
}
