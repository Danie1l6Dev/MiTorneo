<?php

namespace Tests\Feature\Tournaments;

use App\Enums\ScheduleFormat;
use App\Models\Category;
use App\Models\CompetitionPhase;
use App\Models\Group;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeagueScheduleGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_generate_a_single_round_schedule_for_a_category_without_groups(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create();
        Team::factory()->for($tournament)->for($category)->count(4)->create();

        $this->actingAs($user)
            ->post(route('phases.schedule.store', $phase), ['format' => ScheduleFormat::SingleRound->value])
            ->assertRedirect(route('phases.show', $phase));

        $this->assertSame(1, $phase->leagueSchedules()->count());
        $this->assertSame(6, $phase->matches()->count());
        $this->assertSame(3, $phase->matches()->distinct('round_number')->count('round_number'));

        $this->actingAs($user)
            ->get(route('phases.show', $phase))
            ->assertOk();
    }

    public function test_the_phase_page_renders_a_generated_schedule_with_an_odd_number_of_teams(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create();
        Team::factory()->for($tournament)->for($category)->count(5)->create();

        $this->actingAs($user)
            ->post(route('phases.schedule.store', $phase), ['format' => ScheduleFormat::HomeAndAway->value])
            ->assertRedirect(route('phases.show', $phase));

        $this->actingAs($user)
            ->get(route('phases.show', $phase))
            ->assertOk()
            ->assertSee('DESCANSA')
            ->assertSee('Segunda vuelta');
    }

    public function test_a_user_can_generate_a_home_and_away_schedule_per_group(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->usingGroups()->create();
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create();
        $groupA = Group::factory()->for($tournament)->for($category)->create(['name' => 'Grupo A']);
        $groupB = Group::factory()->for($tournament)->for($category)->create(['name' => 'Grupo B']);
        Team::factory()->for($tournament)->for($category)->for($groupA)->count(4)->create();
        Team::factory()->for($tournament)->for($category)->for($groupB)->count(4)->create();

        $this->actingAs($user)
            ->post(route('phases.schedule.store', $phase), ['format' => ScheduleFormat::HomeAndAway->value])
            ->assertRedirect(route('phases.show', $phase));

        $this->assertSame(2, $phase->leagueSchedules()->count());
        $this->assertSame(12, $phase->matches()->where('group_id', $groupA->id)->count());
        $this->assertSame(12, $phase->matches()->where('group_id', $groupB->id)->count());
    }

    public function test_a_schedule_cannot_be_generated_twice_for_the_same_phase(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create();
        Team::factory()->for($tournament)->for($category)->count(4)->create();

        $this->actingAs($user)
            ->post(route('phases.schedule.store', $phase), ['format' => ScheduleFormat::SingleRound->value])
            ->assertRedirect(route('phases.show', $phase));

        $this->actingAs($user)
            ->post(route('phases.schedule.store', $phase), ['format' => ScheduleFormat::SingleRound->value])
            ->assertSessionHasErrors('format');

        $this->assertSame(1, $phase->leagueSchedules()->count());
        $this->assertSame(6, $phase->matches()->count());
    }

    public function test_a_schedule_cannot_be_generated_with_fewer_than_two_teams(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create();
        Team::factory()->for($tournament)->for($category)->create();

        $this->actingAs($user)
            ->post(route('phases.schedule.store', $phase), ['format' => ScheduleFormat::SingleRound->value])
            ->assertSessionHasErrors('format');

        $this->assertSame(0, $phase->leagueSchedules()->count());
    }

    public function test_a_user_cannot_generate_a_schedule_for_another_users_phase(): void
    {
        $owner = User::factory()->create();
        $tournament = Tournament::factory()->for($owner)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create();
        Team::factory()->for($tournament)->for($category)->count(4)->create();

        $intruder = User::factory()->create();

        $this->actingAs($intruder)
            ->post(route('phases.schedule.store', $phase), ['format' => ScheduleFormat::SingleRound->value])
            ->assertForbidden();

        $this->assertSame(0, $phase->leagueSchedules()->count());
    }
}
