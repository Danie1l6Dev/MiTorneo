<?php

namespace Tests\Feature\Tournaments;

use App\Models\Category;
use App\Models\Club;
use App\Models\Player;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The "Jugadores" view of Clubes (ClubController::index with
 * ?view=jugadores): every player of the organizer's own catalog is
 * preloaded once (Player::allForOrganizer()) and searched by name/document
 * entirely client-side as the organizer types (see
 * clubs/index.blade.php -- the same preload-and-filter pattern
 * match-lineup-search.blade.php already uses). These tests cover what's
 * actually server-verifiable: which players get preloaded (ownership
 * scoping) and that both empty-state prompts exist in the markup for
 * Alpine to toggle. The live typing/filtering itself is Alpine-only and
 * was checked in the browser, not here.
 */
class PlayerSearchTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Team}
     */
    private function makeTeam(User $user, string $clubName = 'Nilmar'): array
    {
        $club = Club::factory()->for($user)->create(['name' => $clubName]);
        $category = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'uses_groups' => false]);
        $team = Team::factory()->create(['club_id' => $club->id, 'category_id' => $category->id, 'tournament_id' => null, 'group_id' => null]);

        return [$user, $team];
    }

    public function test_allfororganizer_returns_every_player_of_this_organizers_own_catalog(): void
    {
        $user = User::factory()->create();
        [, $teamOne] = $this->makeTeam($user, 'Nilmar');
        [, $teamTwo] = $this->makeTeam($user, 'Otro Club');
        $playerOne = Player::factory()->for($teamOne)->create();
        $playerTwo = Player::factory()->for($teamTwo)->create();

        $ids = Player::allForOrganizer($user->id)->pluck('id');

        $this->assertTrue($ids->contains($playerOne->id));
        $this->assertTrue($ids->contains($playerTwo->id));
    }

    public function test_allfororganizer_does_not_include_another_organizers_players(): void
    {
        $owner = User::factory()->create();
        [, $ownerTeam] = $this->makeTeam($owner);
        Player::factory()->for($ownerTeam)->create();

        $intruder = User::factory()->create();

        $this->assertCount(0, Player::allForOrganizer($intruder->id));
    }

    public function test_the_jugadores_view_preloads_every_player_for_the_client_side_search(): void
    {
        $user = User::factory()->create();
        [, $team] = $this->makeTeam($user);
        Player::factory()->for($team)->create(['full_name' => 'Juan Pérez', 'document_number' => '111']);
        Player::factory()->for($team)->create(['full_name' => 'Carlos Gómez', 'document_number' => '222']);

        // Both are present in the response markup unconditionally -- the
        // search itself is client-side, so there's nothing server-side to
        // filter by a query string.
        $this->actingAs($user)->get(route('clubs.index', ['view' => 'jugadores']))
            ->assertOk()
            ->assertSee('JUAN PÉREZ')
            ->assertSee('CARLOS GÓMEZ');
    }

    public function test_the_jugadores_view_shows_which_club_and_category_each_player_belongs_to(): void
    {
        $user = User::factory()->create();
        [, $homeTeam] = $this->makeTeam($user, 'Nilmar');
        $club = Club::factory()->for($user)->create(['name' => 'Otro Club']);
        $category = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'uses_groups' => false]);
        $secondTeam = Team::factory()->create(['club_id' => $club->id, 'category_id' => $category->id, 'tournament_id' => null, 'group_id' => null]);

        $player = Player::factory()->for($homeTeam)->create(['full_name' => 'Ana Ruiz']);
        $player->teams()->attach($secondTeam->id);

        $this->actingAs($user)->get(route('clubs.index', ['view' => 'jugadores']))
            ->assertOk()
            ->assertSee('NILMAR')
            ->assertSee('OTRO CLUB');
    }

    public function test_the_jugadores_view_does_not_leak_another_organizers_players(): void
    {
        $owner = User::factory()->create();
        [, $ownerTeam] = $this->makeTeam($owner);
        Player::factory()->for($ownerTeam)->create(['full_name' => 'Jugador Ajeno']);

        $intruder = User::factory()->create();

        $this->actingAs($intruder)->get(route('clubs.index', ['view' => 'jugadores']))
            ->assertOk()
            ->assertDontSee('JUGADOR AJENO');
    }

    public function test_the_jugadores_view_has_both_empty_state_prompts_available_for_alpine_to_toggle(): void
    {
        $user = User::factory()->create();
        [, $team] = $this->makeTeam($user);
        Player::factory()->for($team)->create();

        $this->actingAs($user)->get(route('clubs.index', ['view' => 'jugadores']))
            ->assertOk()
            ->assertSeeText('Escribí un nombre o número de documento')
            ->assertSeeText('No se encontró ningún jugador');
    }

    public function test_the_jugadores_tab_is_available_even_with_no_clubs_yet(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('clubs.index', ['view' => 'jugadores']))
            ->assertOk()
            ->assertSeeText('Todavía no tenés jugadores registrados');
    }
}
