<?php

namespace Tests\Feature\Tournaments;

use App\Models\Category;
use App\Models\Club;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Clubes has two ways to browse the same catalog, picked via ?view= --
 * "categoría" (default, a club with several planteles shows up once per
 * category) and "club" (each club once, with the categories it fields).
 */
class ClubsIndexViewsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_category_view_is_the_default(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create(['name' => 'Nilmar']);
        $category = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'name' => 'Infantil', 'uses_groups' => false]);
        Team::factory()->create(['club_id' => $club->id, 'category_id' => $category->id, 'tournament_id' => null, 'group_id' => null, 'name' => 'Nilmar']);

        $response = $this->actingAs($user)->get(route('clubs.index'));

        $response->assertOk()->assertSee('INFANTIL')->assertSeeInOrder(['INFANTIL', 'NILMAR']);
    }

    public function test_the_club_view_lists_each_club_once_with_its_categories(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create(['name' => 'Nilmar']);
        $categoryA = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'name' => 'Infantil', 'uses_groups' => false]);
        $categoryB = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'name' => 'Cebollita', 'uses_groups' => false]);
        Team::factory()->create(['club_id' => $club->id, 'category_id' => $categoryA->id, 'tournament_id' => null, 'group_id' => null, 'name' => 'Nilmar']);
        Team::factory()->create(['club_id' => $club->id, 'category_id' => $categoryB->id, 'tournament_id' => null, 'group_id' => null, 'name' => 'Nilmar']);

        $response = $this->actingAs($user)->get(route('clubs.index', ['view' => 'club']));

        $response->assertOk()
            ->assertSeeInOrder(['NILMAR', 'INFANTIL', 'CEBOLLITA']);

        // Exactly one "Nilmar" club card, not one per category as the
        // category view would show.
        $response->assertSee('2 planteles');
    }

    public function test_the_club_view_offers_an_edit_button_to_add_planteles_for_other_categories(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create(['name' => 'Nilmar']);
        $category = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'name' => 'Infantil', 'uses_groups' => false]);
        Team::factory()->create(['club_id' => $club->id, 'category_id' => $category->id, 'tournament_id' => null, 'group_id' => null, 'name' => 'Nilmar']);

        $response = $this->actingAs($user)->get(route('clubs.index', ['view' => 'club']));

        $response->assertOk()
            ->assertSee('Editar club')
            ->assertSee(route('clubs.show', $club), false);
    }

    public function test_the_club_view_shows_a_club_with_no_planteles_yet(): void
    {
        $user = User::factory()->create();
        Club::factory()->for($user)->create(['name' => 'Club Nuevo']);
        Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id]);

        $response = $this->actingAs($user)->get(route('clubs.index', ['view' => 'club']));

        $response->assertOk()->assertSee('CLUB NUEVO');
    }

    public function test_a_users_own_clubs_and_categories_do_not_leak_into_another_organizers_club_view(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        Club::factory()->for($owner)->create(['name' => 'Del Otro Organizador']);
        Category::factory()->create(['tournament_id' => null, 'user_id' => $other->id]);

        $response = $this->actingAs($other)->get(route('clubs.index', ['view' => 'club']));

        $response->assertOk()->assertDontSee('Del Otro Organizador');
    }
}
