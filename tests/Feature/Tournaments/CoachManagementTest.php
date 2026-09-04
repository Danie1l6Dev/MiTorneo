<?php

namespace Tests\Feature\Tournaments;

use App\Models\Category;
use App\Models\Coach;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CoachManagementTest extends TestCase
{
    use RefreshDatabase;

    private function makeTeam(User $user): Team
    {
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create();

        return Team::factory()->for($tournament)->for($category)->create();
    }

    public function test_a_user_can_register_a_coach_for_their_team(): void
    {
        $user = User::factory()->create();
        $team = $this->makeTeam($user);

        $this->actingAs($user)->post(route('teams.coach.store', $team), [
            'full_name' => 'Juan Pérez',
            'document_number' => '0102030405',
        ])->assertRedirect(route('teams.show', $team));

        $this->assertDatabaseHas('coaches', [
            'team_id' => $team->id,
            'full_name' => 'Juan Pérez',
            'is_active' => true,
        ]);
    }

    public function test_a_user_can_edit_a_coach(): void
    {
        $user = User::factory()->create();
        $team = $this->makeTeam($user);
        $coach = Coach::factory()->for($team)->create();

        $this->actingAs($user)->put(route('coaches.update', $coach), [
            'full_name' => 'Nuevo DT',
            'document_number' => $coach->document_number,
        ])->assertRedirect(route('teams.show', $team));

        $this->assertSame('Nuevo DT', $coach->fresh()->full_name);
    }

    public function test_a_user_can_toggle_a_coachs_active_status(): void
    {
        $user = User::factory()->create();
        $team = $this->makeTeam($user);
        $coach = Coach::factory()->for($team)->create(['is_active' => true]);

        $this->actingAs($user)->patch(route('coaches.toggle-active', $coach))->assertRedirect();

        $this->assertFalse($coach->fresh()->is_active);
    }

    public function test_a_team_cannot_have_two_active_coaches(): void
    {
        $user = User::factory()->create();
        $team = $this->makeTeam($user);
        Coach::factory()->for($team)->create(['is_active' => true]);

        $this->actingAs($user)->post(route('teams.coach.store', $team), [
            'full_name' => 'Segundo DT',
            'document_number' => '999999',
        ])->assertRedirect(route('teams.show', $team));

        $this->assertSame(1, Coach::query()->where('team_id', $team->id)->where('is_active', true)->count());
        $this->assertDatabaseMissing('coaches', ['full_name' => 'Segundo DT']);
    }

    public function test_a_user_cannot_access_a_coach_of_another_users_team(): void
    {
        $owner = User::factory()->create();
        $team = $this->makeTeam($owner);
        $coach = Coach::factory()->for($team)->create();

        $intruder = User::factory()->create();
        $this->actingAs($intruder);

        $this->get(route('coaches.edit', $coach))->assertForbidden();
        $this->put(route('coaches.update', $coach), [
            'full_name' => 'Hackeado',
            'document_number' => $coach->document_number,
        ])->assertForbidden();
        $this->patch(route('coaches.toggle-active', $coach))->assertForbidden();
        $this->post(route('teams.coach.store', $team), [
            'full_name' => 'Intruso',
            'document_number' => '1',
        ])->assertForbidden();

        $this->assertDatabaseHas('coaches', ['id' => $coach->id, 'full_name' => $coach->full_name]);
    }
}
