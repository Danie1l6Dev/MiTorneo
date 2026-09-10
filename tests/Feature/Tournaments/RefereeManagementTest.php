<?php

namespace Tests\Feature\Tournaments;

use App\Models\Category;
use App\Models\CompetitionPhase;
use App\Models\Referee;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RefereeManagementTest extends TestCase
{
    use RefreshDatabase;

    private function makeMatch(User $user): TournamentMatch
    {
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create();
        $home = Team::factory()->for($tournament)->for($category)->create();
        $away = Team::factory()->for($tournament)->for($category)->create();

        return TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $tournament->id,
            'category_id' => $category->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
        ]);
    }

    public function test_a_user_can_create_a_referee(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('referees.store'), [
            'full_name' => 'Carlos Gómez',
            'document_number' => '12345678',
        ]);

        $referee = Referee::query()->where('document_number', '12345678')->firstOrFail();

        $response->assertRedirect(route('referees.show', $referee));

        $this->assertDatabaseHas('referees', [
            'user_id' => $user->id,
            'full_name' => 'Carlos Gómez',
            'document_number' => '12345678',
        ]);
    }

    public function test_a_user_can_edit_a_referee(): void
    {
        $user = User::factory()->create();
        $referee = Referee::factory()->for($user)->create();

        $this->actingAs($user)->put(route('referees.update', $referee), [
            'full_name' => 'Nombre Actualizado',
            'document_number' => $referee->document_number,
        ])->assertRedirect(route('referees.show', $referee));

        $this->assertSame('Nombre Actualizado', $referee->fresh()->full_name);
    }

    public function test_a_referee_is_not_tied_to_any_tournament(): void
    {
        $user = User::factory()->create();
        $referee = Referee::factory()->for($user)->create();

        $matchInTournamentA = $this->makeMatch($user);
        $matchInTournamentB = $this->makeMatch($user);

        $this->actingAs($user)->put(route('matches.update', $matchInTournamentA), [
            'status' => $matchInTournamentA->status->value,
            'referee_id' => $referee->id,
        ]);

        $this->actingAs($user)->put(route('matches.update', $matchInTournamentB), [
            'status' => $matchInTournamentB->status->value,
            'referee_id' => $referee->id,
        ]);

        $this->assertSame($referee->id, $matchInTournamentA->fresh()->referee_id);
        $this->assertSame($referee->id, $matchInTournamentB->fresh()->referee_id);
        $this->assertNotSame($matchInTournamentA->tournament_id, $matchInTournamentB->tournament_id);
    }

    public function test_a_user_can_assign_a_referee_to_a_match(): void
    {
        $user = User::factory()->create();
        $referee = Referee::factory()->for($user)->create();
        $match = $this->makeMatch($user);

        $this->actingAs($user)->put(route('matches.update', $match), [
            'status' => $match->status->value,
            'referee_id' => $referee->id,
        ])->assertRedirect();

        $this->assertSame($referee->id, $match->fresh()->referee_id);
    }

    public function test_a_match_can_be_saved_without_a_referee(): void
    {
        $user = User::factory()->create();
        $match = $this->makeMatch($user);

        $this->actingAs($user)->put(route('matches.update', $match), [
            'status' => $match->status->value,
            'referee_id' => '',
        ])->assertRedirect();

        $this->assertNull($match->fresh()->referee_id);
    }

    public function test_a_referee_can_be_removed_from_a_match(): void
    {
        $user = User::factory()->create();
        $referee = Referee::factory()->for($user)->create();
        $match = $this->makeMatch($user);
        $match->update(['referee_id' => $referee->id]);

        $this->actingAs($user)->put(route('matches.update', $match), [
            'status' => $match->status->value,
            'referee_id' => '',
        ])->assertRedirect();

        $this->assertNull($match->fresh()->referee_id);
    }

    public function test_a_referee_can_be_reused_across_multiple_matches_and_tournaments(): void
    {
        $user = User::factory()->create();
        $referee = Referee::factory()->for($user)->create();

        $matchOne = $this->makeMatch($user);
        $matchTwo = $this->makeMatch($user);
        $matchThree = $this->makeMatch($user);

        foreach ([$matchOne, $matchTwo, $matchThree] as $match) {
            $match->update(['referee_id' => $referee->id]);
        }

        $this->assertSame(3, $referee->matches()->count());
    }

    public function test_the_referees_index_shows_the_total_matches_directed_by_each_referee(): void
    {
        $user = User::factory()->create();
        $referee = Referee::factory()->for($user)->create(['full_name' => 'Ana Torres']);

        $matchOne = $this->makeMatch($user);
        $matchTwo = $this->makeMatch($user);
        $matchOne->update(['referee_id' => $referee->id]);
        $matchTwo->update(['referee_id' => $referee->id]);

        $response = $this->actingAs($user)->get(route('referees.index'));

        $response->assertOk()->assertSeeText('Ana Torres')->assertSeeText('2');
    }

    public function test_a_referees_match_count_does_not_rely_on_a_stored_counter(): void
    {
        $user = User::factory()->create();
        $referee = Referee::factory()->for($user)->create();

        $this->assertFalse(array_key_exists('matches_count', $referee->getAttributes()));

        $match = $this->makeMatch($user);
        $match->update(['referee_id' => $referee->id]);

        $this->assertSame(1, $referee->fresh()->matches()->count());
    }

    public function test_a_referee_detail_page_lists_the_matches_it_directed(): void
    {
        $user = User::factory()->create();
        $referee = Referee::factory()->for($user)->create();
        $match = $this->makeMatch($user);
        $match->update(['referee_id' => $referee->id]);

        $response = $this->actingAs($user)->get(route('referees.show', $referee));

        $response->assertOk()
            ->assertSeeText($match->tournament->name)
            ->assertSeeText($match->category->name)
            ->assertSeeText($match->competitionPhase->name)
            ->assertSeeText($match->homeTeam->name)
            ->assertSeeText($match->awayTeam->name);
    }

    public function test_a_user_cannot_view_or_edit_another_users_referee(): void
    {
        $owner = User::factory()->create();
        $referee = Referee::factory()->for($owner)->create();

        $intruder = User::factory()->create();
        $this->actingAs($intruder);

        $this->get(route('referees.show', $referee))->assertForbidden();
        $this->get(route('referees.edit', $referee))->assertForbidden();
        $this->put(route('referees.update', $referee), [
            'full_name' => 'Hackeado',
            'document_number' => $referee->document_number,
        ])->assertForbidden();

        $this->assertDatabaseHas('referees', ['id' => $referee->id, 'full_name' => $referee->full_name]);
    }

    public function test_a_user_cannot_assign_another_users_referee_to_their_own_match(): void
    {
        $owner = User::factory()->create();
        $referee = Referee::factory()->for($owner)->create();

        $intruder = User::factory()->create();
        $match = $this->makeMatch($intruder);

        $this->actingAs($intruder)->put(route('matches.update', $match), [
            'status' => $match->status->value,
            'referee_id' => $referee->id,
        ])->assertSessionHasErrors('referee_id');

        $this->assertNull($match->fresh()->referee_id);
    }

    public function test_a_guest_cannot_access_the_referees_index(): void
    {
        $this->get(route('referees.index'))->assertRedirect(route('login'));
    }

    public function test_the_referees_index_only_lists_the_authenticated_users_referees(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $ownReferee = Referee::factory()->for($user)->create(['full_name' => 'Propio Árbitro']);
        Referee::factory()->for($otherUser)->create(['full_name' => 'Ajeno Árbitro']);

        $response = $this->actingAs($user)->get(route('referees.index'));

        $response->assertOk()->assertSeeText('Propio Árbitro')->assertDontSeeText('Ajeno Árbitro');
    }
}
