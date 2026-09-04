<?php

namespace Tests\Feature\Tournaments;

use App\Models\Category;
use App\Models\Player;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlayerManagementTest extends TestCase
{
    use RefreshDatabase;

    private function makeTeam(User $user): Team
    {
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create();

        return Team::factory()->for($tournament)->for($category)->create();
    }

    public function test_a_user_can_create_a_player_for_their_team(): void
    {
        $user = User::factory()->create();
        $team = $this->makeTeam($user);

        $this->actingAs($user)->post(route('teams.players.store', $team), [
            'full_name' => 'Carlos Gómez',
            'document_number' => '0102030405',
            'jersey_number' => 10,
        ])->assertRedirect(route('teams.show', $team));

        $this->assertDatabaseHas('players', [
            'team_id' => $team->id,
            'full_name' => 'Carlos Gómez',
            'document_number' => '0102030405',
            'jersey_number' => 10,
            'is_active' => true,
        ]);
    }

    public function test_a_user_can_edit_a_player(): void
    {
        $user = User::factory()->create();
        $team = $this->makeTeam($user);
        $player = Player::factory()->for($team)->create(['full_name' => 'Nombre Viejo']);

        $this->actingAs($user)->put(route('players.update', $player), [
            'full_name' => 'Nombre Nuevo',
            'document_number' => $player->document_number,
            'jersey_number' => $player->jersey_number,
        ])->assertRedirect(route('teams.show', $team));

        $this->assertSame('Nombre Nuevo', $player->fresh()->full_name);
    }

    public function test_a_user_can_toggle_a_players_active_status(): void
    {
        $user = User::factory()->create();
        $team = $this->makeTeam($user);
        $player = Player::factory()->for($team)->create(['is_active' => true]);

        $this->actingAs($user)->patch(route('players.toggle-active', $player))->assertRedirect();
        $this->assertFalse($player->fresh()->is_active);

        $this->actingAs($user)->patch(route('players.toggle-active', $player))->assertRedirect();
        $this->assertTrue($player->fresh()->is_active);
    }

    public function test_jersey_number_cannot_be_duplicated_among_active_players_of_the_same_team(): void
    {
        $user = User::factory()->create();
        $team = $this->makeTeam($user);
        Player::factory()->for($team)->create(['jersey_number' => 10, 'is_active' => true]);

        $this->actingAs($user)->post(route('teams.players.store', $team), [
            'full_name' => 'Otro Jugador',
            'document_number' => '999999',
            'jersey_number' => 10,
        ])->assertSessionHasErrors('jersey_number');

        $this->assertDatabaseMissing('players', ['team_id' => $team->id, 'full_name' => 'Otro Jugador']);
    }

    public function test_jersey_number_can_be_repeated_across_different_teams(): void
    {
        $user = User::factory()->create();
        $team = $this->makeTeam($user);
        $otherTeam = $this->makeTeam($user);
        Player::factory()->for($team)->create(['jersey_number' => 10]);

        $this->actingAs($user)->post(route('teams.players.store', $otherTeam), [
            'full_name' => 'Jugador Equipo Dos',
            'document_number' => '888888',
            'jersey_number' => 10,
        ])->assertRedirect(route('teams.show', $otherTeam));

        $this->assertDatabaseHas('players', ['team_id' => $otherTeam->id, 'jersey_number' => 10]);
    }

    public function test_document_number_cannot_be_duplicated_among_active_players_of_the_same_team(): void
    {
        $user = User::factory()->create();
        $team = $this->makeTeam($user);
        Player::factory()->for($team)->create(['document_number' => '12345678', 'is_active' => true]);

        $this->actingAs($user)->post(route('teams.players.store', $team), [
            'full_name' => 'Otro Jugador',
            'document_number' => '12345678',
            'jersey_number' => 22,
        ])->assertSessionHasErrors('document_number');
    }

    public function test_a_created_player_belongs_to_the_correct_team(): void
    {
        $user = User::factory()->create();
        $team = $this->makeTeam($user);

        $this->actingAs($user)->post(route('teams.players.store', $team), [
            'full_name' => 'Jugador Correcto',
            'document_number' => '55555',
            'jersey_number' => 5,
        ]);

        $player = Player::query()->firstWhere('full_name', 'Jugador Correcto');
        $this->assertNotNull($player);
        $this->assertSame($team->id, $player->team_id);
    }

    public function test_a_user_cannot_access_a_player_of_another_users_team(): void
    {
        $owner = User::factory()->create();
        $team = $this->makeTeam($owner);
        $player = Player::factory()->for($team)->create();

        $intruder = User::factory()->create();
        $this->actingAs($intruder);

        $this->get(route('players.edit', $player))->assertForbidden();
        $this->put(route('players.update', $player), [
            'full_name' => 'Hackeado',
            'document_number' => $player->document_number,
            'jersey_number' => $player->jersey_number,
        ])->assertForbidden();
        $this->patch(route('players.toggle-active', $player))->assertForbidden();
        $this->post(route('teams.players.store', $team), [
            'full_name' => 'Intruso',
            'document_number' => '1',
            'jersey_number' => 77,
        ])->assertForbidden();

        $this->assertDatabaseHas('players', ['id' => $player->id, 'full_name' => $player->full_name]);
    }
}
