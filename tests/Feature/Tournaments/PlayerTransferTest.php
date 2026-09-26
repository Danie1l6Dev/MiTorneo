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
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * "Transferir jugador": moving a player to another club in one step, with a
 * date and a note that go into their history.
 */
class PlayerTransferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-26 09:00:00');
    }

    private function teamFor(Club $club, string $category, ?int $birthYearTo = null): Team
    {
        return Team::factory()->create([
            'club_id' => $club->id,
            'category_id' => Category::factory()->create(['tournament_id' => null, 'user_id' => $club->user_id, 'name' => $category, 'uses_groups' => false, 'birth_year_to' => $birthYearTo])->id,
            'tournament_id' => null,
            'group_id' => null,
            'name' => $club->name,
        ]);
    }

    /**
     * A player at Club Origen (an open line since March), and a second club to go to.
     *
     * @return array{user: User, player: Player, origin: Team, clubB: Club, teamB1: Team, teamB2: Team}
     */
    private function scenario(): array
    {
        $user = User::factory()->create();
        $clubA = Club::factory()->for($user)->create(['name' => 'Club Origen']);
        $clubB = Club::factory()->for($user)->create(['name' => 'Club Destino']);

        $origin = $this->teamFor($clubA, 'Infantil');
        $teamB1 = $this->teamFor($clubB, 'Infantil');
        $teamB2 = $this->teamFor($clubB, 'Juvenil');

        $player = Player::factory()->create(['team_id' => $origin->id, 'full_name' => 'Juan Transferible', 'birth_date' => '2010-04-01', 'jersey_number' => 8, 'is_active' => true]);
        Carbon::setTestNow('2026-03-01 09:00:00');
        app(PlayerRosterService::class)->enrollNew($player);
        Carbon::setTestNow('2026-09-26 09:00:00');

        return compact('user', 'player', 'origin', 'clubB', 'teamB1', 'teamB2');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $data, array $overrides = []): array
    {
        return array_replace([
            'club_id' => $data['clubB']->id,
            'team_ids' => [$data['teamB1']->id],
            'date' => '2026-09-20',
            'notes' => 'Cambio de domicilio',
        ], $overrides);
    }

    public function test_the_form_offers_the_other_clubs_with_their_planteles_and_whether_the_age_fits(): void
    {
        $data = $this->scenario();
        // A very young category the 2010-born player doesn't fit.
        $young = $this->teamFor($data['clubB'], 'Cebollita', 2019);

        $response = $this->actingAs($data['user'])->get(route('players.transfer.create', $data['player']))->assertOk();

        $clubs = $response->viewData('clubs');

        // The club he's at is not offered.
        $this->assertSame(['CLUB DESTINO'], array_column($clubs, 'name'));
        $eligible = collect($clubs[0]['teams'])->pluck('eligible', 'id');
        $this->assertTrue($eligible[$data['teamB1']->id]);
        $this->assertFalse($eligible[$young->id]);
        $this->assertSame('2026-09-26', $response->viewData('today'));

        $response->assertSee('Transferir jugador')->assertSee('CLUB ORIGEN')->assertSee('Fecha de la transferencia');
    }

    public function test_the_ficha_offers_the_transfer(): void
    {
        $data = $this->scenario();

        $this->actingAs($data['user'])
            ->get(route('players.show', $data['player']))
            ->assertOk()
            ->assertSee(route('players.transfer.create', $data['player']), false)
            ->assertSee('Transferir');
    }

    public function test_transferring_moves_the_player_and_writes_the_history(): void
    {
        $data = $this->scenario();

        $this->actingAs($data['user'])
            ->post(route('players.transfer.store', $data['player']), $this->payload($data, ['team_ids' => [$data['teamB2']->id, $data['teamB1']->id], 'jersey_number' => 11]))
            ->assertRedirect(route('players.show', $data['player']))
            ->assertSessionHas('status');

        $player = $data['player']->fresh();

        // One of the two chosen planteles is his own (team_id), the other an extra one.
        $this->assertContains($player->team_id, [$data['teamB1']->id, $data['teamB2']->id]);
        $this->assertEqualsCanonicalizing([$data['teamB1']->id, $data['teamB2']->id], $player->allTeams()->pluck('id')->all());
        $this->assertSame(11, $player->jersey_number);
        $this->assertTrue($player->is_active);
        $this->assertFalse($player->allTeams()->contains('id', $data['origin']->id));

        $lines = $player->teamHistory()->reorder('id')->get();

        $old = $lines->firstWhere('team_id', $data['origin']->id);
        $this->assertSame('2026-09-20', $old->ended_on->toDateString());
        $this->assertSame(RosterEndReason::Transferred, $old->end_reason);

        $new = $lines->where('team_id', '!=', $data['origin']->id);
        $this->assertCount(2, $new);
        $this->assertTrue($new->every(fn ($line) => $line->isOpen() && $line->start_reason === RosterStartReason::Transferred && $line->started_on->toDateString() === '2026-09-20' && $line->notes === 'Cambio de domicilio' && $line->club_name === 'CLUB DESTINO'));
    }

    public function test_the_ficha_confirms_the_transfer_and_shows_it_in_the_timeline(): void
    {
        $data = $this->scenario();

        $this->actingAs($data['user'])
            ->followingRedirects()
            ->post(route('players.transfer.store', $data['player']), $this->payload($data))
            ->assertOk()
            ->assertSee('JUAN TRANSFERIBLE fue transferido a CLUB DESTINO.')
            ->assertSee('Transferido desde otro club')
            ->assertSee('Cambio de domicilio')
            ->assertSee('Desde 20/09/2026')
            ->assertSee('hasta 20/09/2026');
    }

    public function test_an_active_player_can_be_transferred_without_deactivating_them_first(): void
    {
        $data = $this->scenario();
        $this->assertTrue($data['player']->is_active);

        $this->actingAs($data['user'])
            ->post(route('players.transfer.store', $data['player']), $this->payload($data))
            ->assertSessionHasNoErrors();

        $this->assertSame($data['teamB1']->id, $data['player']->fresh()->team_id);
    }

    public function test_an_inactive_player_comes_back_active(): void
    {
        $data = $this->scenario();
        $data['player']->forceFill(['is_active' => false])->save();

        $this->actingAs($data['user'])->post(route('players.transfer.store', $data['player']), $this->payload($data));

        $this->assertTrue($data['player']->fresh()->is_active);
    }

    public function test_the_players_own_club_is_not_a_valid_destination(): void
    {
        $data = $this->scenario();

        $this->actingAs($data['user'])
            ->post(route('players.transfer.store', $data['player']), $this->payload($data, ['club_id' => $data['origin']->club_id, 'team_ids' => [$data['origin']->id]]))
            ->assertSessionHasErrors('club_id');

        $this->assertSame($data['origin']->id, $data['player']->fresh()->team_id);
    }

    public function test_a_club_of_another_organizer_is_rejected(): void
    {
        $data = $this->scenario();
        $foreignClub = Club::factory()->create();
        $foreignTeam = $this->teamFor($foreignClub, 'Infantil');

        $this->actingAs($data['user'])
            ->post(route('players.transfer.store', $data['player']), $this->payload($data, ['club_id' => $foreignClub->id, 'team_ids' => [$foreignTeam->id]]))
            ->assertSessionHasErrors('club_id');
    }

    public function test_a_plantel_that_is_not_of_the_chosen_club_is_rejected(): void
    {
        $data = $this->scenario();

        $this->actingAs($data['user'])
            ->post(route('players.transfer.store', $data['player']), $this->payload($data, ['team_ids' => [$data['origin']->id]]))
            ->assertSessionHasErrors('team_ids');
    }

    public function test_a_plantel_whose_category_the_age_does_not_fit_is_rejected(): void
    {
        $data = $this->scenario();
        $young = $this->teamFor($data['clubB'], 'Cebollita', 2019);

        $this->actingAs($data['user'])
            ->post(route('players.transfer.store', $data['player']), $this->payload($data, ['team_ids' => [$young->id]]))
            ->assertSessionHasErrors('team_ids');

        $this->assertSame($data['origin']->id, $data['player']->fresh()->team_id);
    }

    public function test_at_least_one_plantel_is_needed(): void
    {
        $data = $this->scenario();

        $this->actingAs($data['user'])
            ->post(route('players.transfer.store', $data['player']), $this->payload($data, ['team_ids' => []]))
            ->assertSessionHasErrors('team_ids');
    }

    public function test_a_player_without_a_birth_date_must_have_it_completed_first(): void
    {
        $data = $this->scenario();
        $data['player']->forceFill(['birth_date' => null])->save();

        $this->actingAs($data['user'])
            ->get(route('players.transfer.create', $data['player']))
            ->assertOk()
            ->assertSee('Falta la fecha de nacimiento');

        $this->actingAs($data['user'])
            ->post(route('players.transfer.store', $data['player']), $this->payload($data))
            ->assertSessionHasErrors('team_ids');
    }

    public function test_the_date_cannot_be_in_the_future_or_before_they_joined_the_plantel_they_leave(): void
    {
        $data = $this->scenario();

        $this->actingAs($data['user'])
            ->post(route('players.transfer.store', $data['player']), $this->payload($data, ['date' => '2026-10-15']))
            ->assertSessionHasErrors('date');

        // Their line at Club Origen opened on 2026-03-01.
        $this->actingAs($data['user'])
            ->post(route('players.transfer.store', $data['player']), $this->payload($data, ['date' => '2026-02-01']))
            ->assertSessionHasErrors('date');
    }

    public function test_the_dorsal_cannot_clash_with_an_active_player_of_the_new_plantel(): void
    {
        $data = $this->scenario();
        Player::factory()->create(['team_id' => $data['teamB1']->id, 'jersey_number' => 11, 'is_active' => true]);

        $this->actingAs($data['user'])
            ->post(route('players.transfer.store', $data['player']), $this->payload($data, ['jersey_number' => 11]))
            ->assertSessionHasErrors('jersey_number');

        $this->assertSame($data['origin']->id, $data['player']->fresh()->team_id);
    }

    public function test_another_organizer_cannot_transfer_the_player(): void
    {
        $data = $this->scenario();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get(route('players.transfer.create', $data['player']))->assertForbidden();
        $this->actingAs($stranger)->post(route('players.transfer.store', $data['player']), $this->payload($data))->assertForbidden();

        $this->assertSame($data['origin']->id, $data['player']->fresh()->team_id);
    }

    public function test_guests_are_sent_to_login(): void
    {
        $data = $this->scenario();

        $this->get(route('players.transfer.create', $data['player']))->assertRedirect(route('login'));
    }
}
