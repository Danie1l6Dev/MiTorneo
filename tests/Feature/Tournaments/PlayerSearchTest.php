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
 * The "Buscar jugador" section (PlayerSearchController, players.index): every
 * player of the organizer's own catalog is preloaded once
 * (Player::allForOrganizer(), via PlayerProfileService::searchCatalog()) and
 * searched entirely client-side as the organizer types. These tests cover what
 * is server-verifiable: which players get preloaded (ownership scoping) and
 * that both empty-state prompts exist in the markup for Alpine to toggle. The
 * live typing/filtering itself is Alpine-only and was checked in the browser.
 * The ficha a result opens is covered by PlayerSearchAndProfileTest.
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

    public function test_the_search_page_preloads_every_player_for_the_client_side_search(): void
    {
        $user = User::factory()->create();
        [, $team] = $this->makeTeam($user);
        Player::factory()->for($team)->create(['full_name' => 'Juan Pérez', 'document_number' => '111']);
        Player::factory()->for($team)->create(['full_name' => 'Carlos Gómez', 'document_number' => '222']);

        // Both are in the page unconditionally -- the search itself is
        // client-side, so there's nothing server-side to filter by a query string.
        $catalog = $this->actingAs($user)->get(route('players.index'))
            ->assertOk()
            ->viewData('catalog');

        $this->assertEqualsCanonicalizing(['CARLOS GÓMEZ', 'JUAN PÉREZ'], array_column($catalog, 'name'));
        $this->assertEqualsCanonicalizing(['111', '222'], array_column($catalog, 'document'));
    }

    public function test_the_search_page_shows_which_club_and_category_each_player_belongs_to(): void
    {
        $user = User::factory()->create();
        [, $homeTeam] = $this->makeTeam($user, 'Nilmar');
        $club = Club::factory()->for($user)->create(['name' => 'Otro Club']);
        $category = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'name' => 'Infantil', 'uses_groups' => false]);
        $secondTeam = Team::factory()->create(['club_id' => $club->id, 'category_id' => $category->id, 'tournament_id' => null, 'group_id' => null]);

        $player = Player::factory()->for($homeTeam)->create(['full_name' => 'Ana Ruiz']);
        $player->teams()->attach($secondTeam->id);

        $catalog = $this->actingAs($user)->get(route('players.index'))->assertOk()->viewData('catalog');

        $clubs = collect($catalog[0]['teams'])->pluck('club')->all();
        $this->assertEqualsCanonicalizing(['NILMAR', 'OTRO CLUB'], $clubs);
        $this->assertContains('INFANTIL', collect($catalog[0]['teams'])->pluck('category')->all());
        // Both clubs are searchable by name.
        $this->assertStringContainsString('nilmar', $catalog[0]['haystack']);
        $this->assertStringContainsString('otro club', $catalog[0]['haystack']);
    }

    public function test_the_search_page_does_not_leak_another_organizers_players(): void
    {
        $owner = User::factory()->create();
        [, $ownerTeam] = $this->makeTeam($owner);
        Player::factory()->for($ownerTeam)->create(['full_name' => 'Jugador Ajeno']);

        $intruder = User::factory()->create();

        $response = $this->actingAs($intruder)->get(route('players.index'))->assertOk();

        $this->assertSame([], $response->viewData('catalog'));
        $response->assertDontSee('JUGADOR AJENO')->assertDontSee('Jugador Ajeno');
    }

    public function test_the_search_page_has_both_empty_state_prompts_available_for_alpine_to_toggle(): void
    {
        $user = User::factory()->create();
        [, $team] = $this->makeTeam($user);
        Player::factory()->for($team)->create();

        $this->actingAs($user)->get(route('players.index'))
            ->assertOk()
            ->assertSeeText('Escribe un documento, un nombre o un club para buscar.')
            ->assertSeeText('No se encontró ningún jugador');
    }

    public function test_the_search_page_is_available_even_with_no_clubs_yet(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('players.index'))
            ->assertOk()
            ->assertSeeText('Todavía no hay jugadores registrados');
    }
}
