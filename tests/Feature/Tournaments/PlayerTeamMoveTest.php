<?php

namespace Tests\Feature\Tournaments;

use App\Enums\RosterEndReason;
use App\Enums\RosterStartReason;
use App\Models\Category;
use App\Models\Club;
use App\Models\Player;
use App\Models\Team;
use App\Models\User;
use App\Services\PlayerRosterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Cambiar de plantel" (PlayerController::moveTeam()): swapping one of a
 * player's planteles for another one of the same club -- the grupo A -> grupo
 * B case, where the club rearranged who plays in which plantel -- instead of
 * deactivating them and registering them again.
 */
class PlayerTeamMoveTest extends TestCase
{
    use RefreshDatabase;

    private function makeTeam(Club $club, Category $category, string $name): Team
    {
        return Team::factory()->create([
            'club_id' => $club->id,
            'category_id' => $category->id,
            'tournament_id' => null,
            'group_id' => null,
            'name' => $name,
        ]);
    }

    private function preProny(User $user): Category
    {
        return Category::factory()->create([
            'tournament_id' => null,
            'user_id' => $user->id,
            'name' => 'Pre-Pony',
            'birth_year_from' => 2015,
            'birth_year_to' => 2016,
        ]);
    }

    public function test_moving_the_primary_plantel_to_another_group_of_the_same_category_replaces_the_link(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create(['name' => 'Nilmar']);
        $category = $this->preProny($user);

        $groupA = $this->makeTeam($club, $category, 'Nilmar (A)');
        $groupB = $this->makeTeam($club, $category, 'Nilmar (B)');

        $player = Player::factory()->for($groupA)->create(['full_name' => 'Sherwin Escobar', 'birth_date' => '2015-06-25']);
        // Through the service, so he has the open history line a real
        // registration would have left -- that's the one the move closes.
        app(PlayerRosterService::class)->enrollNew($player);

        $this->actingAs($user)
            ->post(route('players.teams.move', [$player, $groupA]), ['destination_team_id' => $groupB->id])
            ->assertRedirect(route('players.edit', $player));

        $player->refresh();

        // Moved, not added alongside: he's in B and no longer in A.
        $this->assertSame($groupB->id, $player->team_id);
        $this->assertFalse($player->teams()->where('teams.id', $groupA->id)->exists());
        $this->assertFalse($groupA->players()->whereKey($player->id)->exists());

        $lines = $player->teamHistory()->reorder('id')->get();
        $this->assertSame($groupA->id, $lines[0]->team_id);
        $this->assertSame(RosterEndReason::Moved, $lines[0]->end_reason);
        $this->assertSame($groupB->id, $lines[1]->team_id);
        $this->assertSame(RosterStartReason::Moved, $lines[1]->start_reason);
        $this->assertNull($lines[1]->ended_on);
    }

    public function test_moving_a_plantel_linked_via_the_pivot_moves_only_that_link_and_keeps_the_jersey(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create(['name' => 'Nilmar']);

        $babyCategory = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'name' => 'Baby', 'birth_year_from' => 2020, 'birth_year_to' => 2021]);
        $category = $this->preProny($user);

        $primary = $this->makeTeam($club, $babyCategory, 'Nilmar Baby');
        $groupA = $this->makeTeam($club, $category, 'Nilmar (A)');
        $groupB = $this->makeTeam($club, $category, 'Nilmar (B)');

        $player = Player::factory()->for($primary)->create(['birth_date' => '2015-06-25']);
        $player->teams()->attach($groupA->id, ['jersey_number' => 9]);

        $this->actingAs($user)
            ->post(route('players.teams.move', [$player, $groupA]), ['destination_team_id' => $groupB->id])
            ->assertRedirect(route('players.edit', $player));

        $player->refresh();

        // The primary plantel (a different category) is untouched; only the
        // pivot row that pointed at A moves, dorsal included.
        $this->assertSame($primary->id, $player->team_id);
        $this->assertFalse($player->teams()->where('teams.id', $groupA->id)->exists());
        $this->assertSame(9, $player->teams()->where('teams.id', $groupB->id)->first()?->pivot->jersey_number);
    }

    public function test_a_destination_outside_the_players_own_club_is_rejected_without_moving_anything(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create(['name' => 'Nilmar']);
        $otherClub = Club::factory()->for($user)->create(['name' => 'Juventus']);
        $category = $this->preProny($user);

        $groupA = $this->makeTeam($club, $category, 'Nilmar (A)');
        $otherClubTeam = $this->makeTeam($otherClub, $category, 'Juventus');

        $player = Player::factory()->for($groupA)->create(['birth_date' => '2015-06-25']);

        $this->actingAs($user)
            ->from(route('players.edit', $player))
            ->post(route('players.teams.move', [$player, $groupA]), ['destination_team_id' => $otherClubTeam->id])
            ->assertRedirect(route('players.edit', $player))
            ->assertSessionHas('error');

        $this->assertSame($groupA->id, $player->refresh()->team_id);
        $this->assertSame(0, $player->teamHistory()->count());
    }

    public function test_a_younger_category_is_not_offered_as_a_destination(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create(['name' => 'Nilmar']);

        $category = $this->preProny($user);
        $youngerCategory = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'name' => 'Baby', 'birth_year_from' => 2020, 'birth_year_to' => 2021]);

        $groupA = $this->makeTeam($club, $category, 'Nilmar (A)');
        $youngerTeam = $this->makeTeam($club, $youngerCategory, 'Nilmar Baby');

        $player = Player::factory()->for($groupA)->create(['birth_date' => '2015-06-25']);

        $this->assertFalse($player->moveCandidateTeams($groupA)->contains('id', $youngerTeam->id));

        $this->actingAs($user)
            ->from(route('players.edit', $player))
            ->post(route('players.teams.move', [$player, $groupA]), ['destination_team_id' => $youngerTeam->id])
            ->assertSessionHas('error');

        $this->assertSame($groupA->id, $player->refresh()->team_id);
    }

    public function test_the_edit_page_offers_the_other_group_of_the_same_category_as_a_destination(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create(['name' => 'Nilmar']);
        $category = $this->preProny($user);

        $groupA = $this->makeTeam($club, $category, 'Nilmar (A)');
        $groupB = $this->makeTeam($club, $category, 'Nilmar (B)');

        $player = Player::factory()->for($groupA)->create(['birth_date' => '2015-06-25']);

        $this->actingAs($user)
            ->get(route('players.edit', $player))
            ->assertOk()
            ->assertSee('Cambiar de plantel')
            ->assertSee(route('players.teams.move', [$player, $groupA]), escape: false)
            ->assertSee('value="'.$groupB->id.'"', escape: false);
    }
}
