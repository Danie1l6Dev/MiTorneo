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

    public function test_moving_several_players_at_once_sends_every_marked_one_to_the_chosen_plantel(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create(['name' => 'Nilmar']);
        $category = $this->preProny($user);

        $groupA = $this->makeTeam($club, $category, 'Nilmar (A)');
        $groupB = $this->makeTeam($club, $category, 'Nilmar (B)');

        $moving = Player::factory()->count(2)->for($groupA)->create(['birth_date' => '2015-06-25']);
        $staying = Player::factory()->for($groupA)->create(['birth_date' => '2015-06-25']);

        $this->actingAs($user)
            ->post(route('teams.players.move.store', $groupA), [
                'destination_team_id' => $groupB->id,
                'player_ids' => $moving->pluck('id')->all(),
            ])
            ->assertRedirect(route('teams.show', $groupA));

        foreach ($moving as $player) {
            $this->assertSame($groupB->id, $player->refresh()->team_id);
        }

        // Only the marked ones move -- the rest of the roster stays put.
        $this->assertSame($groupA->id, $staying->refresh()->team_id);
    }

    public function test_a_marked_player_the_destination_does_not_fit_is_skipped_instead_of_failing_the_batch(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create(['name' => 'Nilmar']);

        $category = $this->preProny($user);
        $youngerCategory = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'name' => 'Baby', 'birth_year_from' => 2020, 'birth_year_to' => 2021]);

        $origin = $this->makeTeam($club, $category, 'Nilmar (A)');
        $destination = $this->makeTeam($club, $youngerCategory, 'Nilmar Baby');

        // Born 2020, playing up in Pre-Pony: Baby is his own category, so he
        // fits it. The 2015 one doesn't -- the age rule allows playing up
        // into an older category, never down into a younger one.
        $fits = Player::factory()->for($origin)->create(['birth_date' => '2020-03-01']);
        $doesNotFit = Player::factory()->for($origin)->create(['birth_date' => '2015-06-25']);

        $this->actingAs($user)
            ->post(route('teams.players.move.store', $origin), [
                'destination_team_id' => $destination->id,
                'player_ids' => [$fits->id, $doesNotFit->id],
            ])
            ->assertRedirect(route('teams.show', $origin))
            ->assertSessionHas('status');

        $this->assertSame($destination->id, $fits->refresh()->team_id);
        $this->assertSame($origin->id, $doesNotFit->refresh()->team_id);
    }

    public function test_moving_several_players_without_marking_anyone_is_rejected(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create(['name' => 'Nilmar']);
        $category = $this->preProny($user);

        $groupA = $this->makeTeam($club, $category, 'Nilmar (A)');
        $groupB = $this->makeTeam($club, $category, 'Nilmar (B)');

        $player = Player::factory()->for($groupA)->create(['birth_date' => '2015-06-25']);

        $this->actingAs($user)
            ->from(route('teams.players.move.create', $groupA))
            ->post(route('teams.players.move.store', $groupA), ['destination_team_id' => $groupB->id])
            ->assertRedirect(route('teams.players.move.create', $groupA))
            ->assertSessionHas('error');

        $this->assertSame($groupA->id, $player->refresh()->team_id);
    }

    public function test_a_destination_plantel_of_another_club_is_rejected_in_the_bulk_move(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create(['name' => 'Nilmar']);
        $otherClub = Club::factory()->for($user)->create(['name' => 'Juventus']);
        $category = $this->preProny($user);

        $groupA = $this->makeTeam($club, $category, 'Nilmar (A)');
        $otherClubTeam = $this->makeTeam($otherClub, $category, 'Juventus');

        $player = Player::factory()->for($groupA)->create(['birth_date' => '2015-06-25']);

        $this->actingAs($user)
            ->from(route('teams.players.move.create', $groupA))
            ->post(route('teams.players.move.store', $groupA), [
                'destination_team_id' => $otherClubTeam->id,
                'player_ids' => [$player->id],
            ])
            ->assertSessionHas('error');

        $this->assertSame($groupA->id, $player->refresh()->team_id);
    }

    public function test_the_plantel_page_links_to_the_bulk_move_picker(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create(['name' => 'Nilmar']);
        $category = $this->preProny($user);

        $groupA = $this->makeTeam($club, $category, 'Nilmar (A)');
        $this->makeTeam($club, $category, 'Nilmar (B)');

        Player::factory()->for($groupA)->create(['birth_date' => '2015-06-25']);

        $this->actingAs($user)
            ->get(route('teams.show', $groupA))
            ->assertOk()
            ->assertSee('Mover jugadores')
            ->assertSee(route('teams.players.move.create', $groupA), escape: false);

        $this->actingAs($user)
            ->get(route('teams.players.move.create', $groupA))
            ->assertOk()
            ->assertSee('Mover jugadores')
            // Team names are stored uppercased (NormalizesToUppercase).
            ->assertSee('NILMAR (B)')
            // The submit button is disabled until someone is marked, and Flux
            // reads [disabled] on a submit button as "submitting": without
            // :loading="false" it would hide the label behind a spinner that
            // never stops.
            ->assertDontSee('data-flux-loading-indicator', escape: false);
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
