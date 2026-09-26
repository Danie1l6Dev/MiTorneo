<?php

namespace Tests\Feature\Tournaments;

use App\Models\Category;
use App\Models\Club;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Por categoría" and "Por club" have a live search box (like the players'
 * one): every card is rendered up front and Alpine only toggles its
 * visibility against the typed text, ignoring case and accents -- so the page
 * carries, per card, its name already lowercased and stripped of accents
 * (data-name), and the list of those names for the "no results" state.
 */
class ClubsIndexLiveSearchTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{user: User}
     */
    private function organizerWithClubsAndCategories(): array
    {
        $user = User::factory()->create();

        $niñosUnidos = Club::factory()->for($user)->create(['name' => 'Niños Unidos']);
        $nilmar = Club::factory()->for($user)->create(['name' => 'Nilmar']);
        $infantil = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'name' => 'Infantil', 'uses_groups' => false, 'birth_year_to' => 2013]);
        $categoriaEspecial = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'name' => 'Categoría Ñandú', 'uses_groups' => false, 'birth_year_to' => 2019]);

        foreach ([$niñosUnidos, $nilmar] as $club) {
            Team::factory()->create(['club_id' => $club->id, 'category_id' => $infantil->id, 'tournament_id' => null, 'group_id' => null, 'name' => $club->name]);
        }

        return ['user' => $user];
    }

    public function test_the_category_view_has_a_live_search_box_and_each_card_carries_its_searchable_name(): void
    {
        ['user' => $user] = $this->organizerWithClubsAndCategories();

        $this->actingAs($user)
            ->get(route('clubs.index'))
            ->assertOk()
            ->assertSee('Nombre de la categoría...', false)
            // Lowercase, and with the accents and the ñ stripped.
            ->assertSee('data-name="infantil"', false)
            ->assertSee('data-name="categoria nandu"', false)
            ->assertSee('No se encontró ninguna categoría con esa búsqueda.');
    }

    public function test_the_club_view_has_a_live_search_box_and_each_card_carries_its_searchable_name(): void
    {
        ['user' => $user] = $this->organizerWithClubsAndCategories();

        $this->actingAs($user)
            ->get(route('clubs.index', ['view' => 'club']))
            ->assertOk()
            ->assertSee('Nombre del club...', false)
            ->assertSee('data-name="ninos unidos"', false)
            ->assertSee('data-name="nilmar"', false)
            ->assertSee('No se encontró ningún club con esa búsqueda.');
    }

    public function test_the_search_filters_in_the_browser_without_asking_the_server(): void
    {
        ['user' => $user] = $this->organizerWithClubsAndCategories();

        $html = $this->actingAs($user)->get(route('clubs.index'))->assertOk()->getContent();

        // Each list starts from its own array of names and filters the cards with x-show.
        // One toggle per card: two clubs and two categories.
        $this->assertSame(4, substr_count($html, 'x-show="matches($el.dataset.name)"'));
        $this->assertSame(2, substr_count($html, 'get hasResults()'));
        $this->assertStringContainsString('x-model="query"', $html);
        // The names array carries the normalized names too (used for the empty state).
        $this->assertStringContainsString('categoria nandu', $html);
        $this->assertStringContainsString('ninos unidos', $html);
    }

    public function test_the_players_search_is_still_there(): void
    {
        ['user' => $user] = $this->organizerWithClubsAndCategories();

        $this->actingAs($user)
            ->get(route('clubs.index', ['view' => 'jugadores']))
            ->assertOk()
            ->assertSee('Nombre o documento del jugador...', false);
    }

    public function test_no_search_box_is_offered_when_there_is_nothing_to_search(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('clubs.index'))
            ->assertOk()
            ->assertDontSee('Nombre de la categoría...', false)
            ->assertDontSee('Nombre del club...', false);
    }
}
