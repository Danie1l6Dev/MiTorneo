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
 * The live document_number lookup behind both "agregar jugador" forms
 * (players.search route, PlayerController::search()) -- lets the organizer
 * find out a document is already registered, and see every plantel it's
 * already on, before finishing the rest of the form. Built directly on
 * Player::findForOrganizer(), so it shares that method's ownership scoping.
 */
class PlayerDocumentLookupTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Team}
     */
    private function makeTeam(User $user, string $clubName = 'Nilmar'): array
    {
        $club = Club::factory()->for($user)->create(['name' => $clubName]);
        $category = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'uses_groups' => false, 'name' => 'Infantil']);
        $team = Team::factory()->create(['club_id' => $club->id, 'category_id' => $category->id, 'tournament_id' => null, 'group_id' => null]);

        return [$user, $team];
    }

    public function test_returns_not_found_for_an_unknown_document(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson(route('players.search', ['document_number' => '999']))
            ->assertOk()
            ->assertJson(['found' => false]);
    }

    public function test_returns_not_found_when_no_document_is_given(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson(route('players.search'))
            ->assertOk()
            ->assertJson(['found' => false]);
    }

    public function test_finds_an_existing_player_by_document_with_their_planteles(): void
    {
        $user = User::factory()->create();
        [, $team] = $this->makeTeam($user, 'Nilmar');
        $player = Player::factory()->for($team)->create([
            'full_name' => 'Juan Pérez',
            'document_number' => '12345678',
            'birth_date' => '2012-05-10',
        ]);

        $response = $this->actingAs($user)->getJson(route('players.search', ['document_number' => '12345678']))
            ->assertOk();

        $response->assertJson([
            'found' => true,
            'player' => [
                'full_name' => 'JUAN PÉREZ',
                'birth_date' => '2012-05-10',
            ],
        ]);
        $this->assertSame($team->id, $response->json('teams.0.id'));
        $this->assertSame($team->name, $response->json('teams.0.name'));
    }

    public function test_lists_every_plantel_the_player_is_registered_to(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create();
        $categoryOne = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'uses_groups' => false]);
        $categoryTwo = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'uses_groups' => false]);
        $teamOne = Team::factory()->create(['club_id' => $club->id, 'category_id' => $categoryOne->id, 'tournament_id' => null, 'group_id' => null]);
        $teamTwo = Team::factory()->create(['club_id' => $club->id, 'category_id' => $categoryTwo->id, 'tournament_id' => null, 'group_id' => null]);

        $player = Player::factory()->for($teamOne)->create(['document_number' => '555']);
        $player->teams()->attach($teamTwo->id);

        $response = $this->actingAs($user)->getJson(route('players.search', ['document_number' => '555']))
            ->assertOk();

        $teamIds = collect($response->json('teams'))->pluck('id');
        $this->assertTrue($teamIds->contains($teamOne->id));
        $this->assertTrue($teamIds->contains($teamTwo->id));
    }

    public function test_does_not_leak_another_organizers_player(): void
    {
        $owner = User::factory()->create();
        [, $ownerTeam] = $this->makeTeam($owner);
        Player::factory()->for($ownerTeam)->create(['document_number' => '777']);

        $intruder = User::factory()->create();

        $this->actingAs($intruder)->getJson(route('players.search', ['document_number' => '777']))
            ->assertOk()
            ->assertJson(['found' => false]);
    }
}
