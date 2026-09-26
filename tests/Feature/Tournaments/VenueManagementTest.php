<?php

namespace Tests\Feature\Tournaments;

use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Tournaments\Concerns\MakesSchedulableMatches;
use Tests\TestCase;

class VenueManagementTest extends TestCase
{
    use MakesSchedulableMatches, RefreshDatabase;

    public function test_a_user_can_register_a_venue(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('venues.store'), ['name' => 'Cancha Parque Boscán'])
            ->assertRedirect(route('venues.index'));

        $this->assertDatabaseHas('venues', ['user_id' => $user->id, 'name' => 'CANCHA PARQUE BOSCÁN']);
    }

    public function test_the_index_lists_only_the_users_own_venues_with_their_match_count(): void
    {
        $user = User::factory()->create();
        $mine = Venue::factory()->for($user)->create(['name' => 'Cancha Propia']);
        Venue::factory()->create(['name' => 'Cancha Ajena']);

        ['tournament' => $tournament] = $this->makeSchedulingTournament($user);
        $category = $this->makeSchedulingCategory($tournament, 'Sub-13', 2013);
        $this->makeSchedulingMatch($tournament, $category, 'Tigres', 'Leones', 1, '2026-09-12 07:30:00', $mine);

        $this->actingAs($user)
            ->get(route('venues.index'))
            ->assertOk()
            ->assertSee('CANCHA PROPIA')
            ->assertDontSee('CANCHA AJENA');
    }

    public function test_the_name_must_be_unique_per_organizer_but_not_across_organizers(): void
    {
        $user = User::factory()->create();
        Venue::factory()->for($user)->create(['name' => 'Cancha Boscán']);

        $this->actingAs($user)
            ->post(route('venues.store'), ['name' => '  cancha   boscán '])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, $user->venues()->count());

        $other = User::factory()->create();

        $this->actingAs($other)
            ->post(route('venues.store'), ['name' => 'Cancha Boscán'])
            ->assertSessionHasNoErrors();
    }

    public function test_a_user_can_rename_a_venue_keeping_its_own_name_valid(): void
    {
        $user = User::factory()->create();
        $venue = Venue::factory()->for($user)->create(['name' => 'Cancha Vieja']);

        $this->actingAs($user)->put(route('venues.update', $venue), ['name' => 'Cancha Vieja'])->assertSessionHasNoErrors();
        $this->actingAs($user)->put(route('venues.update', $venue), ['name' => 'Cancha Nueva'])->assertRedirect(route('venues.index'));

        $this->assertSame('CANCHA NUEVA', $venue->fresh()->name);
    }

    public function test_another_organizer_cannot_edit_update_or_delete_a_venue(): void
    {
        $venue = Venue::factory()->create();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get(route('venues.edit', $venue))->assertForbidden();
        $this->actingAs($stranger)->put(route('venues.update', $venue), ['name' => 'Otra'])->assertForbidden();
        $this->actingAs($stranger)->delete(route('venues.destroy', $venue))->assertForbidden();
    }

    public function test_deleting_a_venue_keeps_its_matches_but_leaves_them_without_cancha(): void
    {
        ['user' => $user, 'tournament' => $tournament] = $this->makeSchedulingTournament();
        $venue = $this->makeSchedulingVenue($user, 'Cancha Los Ídolos');
        $category = $this->makeSchedulingCategory($tournament, 'Sub-13', 2013);
        $match = $this->makeSchedulingMatch($tournament, $category, 'Tigres', 'Leones', 1, '2026-09-12 07:30:00', $venue);

        $this->actingAs($user)->delete(route('venues.destroy', $venue))->assertRedirect(route('venues.index'));

        $this->assertModelMissing($venue);
        $match->refresh();
        $this->assertNull($match->venue_id);
        $this->assertSame('2026-09-12 07:30:00', $match->scheduled_at->format('Y-m-d H:i:s'));
    }

    public function test_the_sidebar_links_to_the_venues_section(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('venues.index'))
            ->assertOk()
            ->assertSee(route('venues.index'), false)
            ->assertSee('Canchas');
    }
}
