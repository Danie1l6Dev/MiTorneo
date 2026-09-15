<?php

namespace Tests\Feature\Tournaments;

use App\Enums\MatchEventType;
use App\Enums\SanctionType;
use App\Models\Category;
use App\Models\Club;
use App\Models\MatchEvent;
use App\Models\Player;
use App\Models\Sanction;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The "find or link" player flow for global teams -- see
 * docs/plan-reestructuracion/01-clubes-equipos-categorias-globales.md
 * (T01-11, T01-27). This is what actually solves the client's original
 * complaint: a kid playing in two categories no longer means typing their
 * data in twice.
 */
class GlobalPlayerLinkingTest extends TestCase
{
    use RefreshDatabase;

    private function makeTeam(User $user, string $categoryName = 'Infantil', ?int $birthYearTo = null): Team
    {
        $club = Club::factory()->for($user)->create();
        $category = Category::factory()->create([
            'tournament_id' => null,
            'user_id' => $user->id,
            'name' => $categoryName,
            'uses_groups' => false,
            'birth_year_to' => $birthYearTo,
        ]);

        return Team::factory()->create([
            'club_id' => $club->id,
            'category_id' => $category->id,
            'tournament_id' => null,
            'group_id' => null,
        ]);
    }

    public function test_a_brand_new_player_is_created_with_a_direct_team_id(): void
    {
        $user = User::factory()->create();
        $team = $this->makeTeam($user);

        $this->actingAs($user)
            ->post(route('teams.players.store', $team), [
                'document_number' => '111',
                'full_name' => 'Juan Pérez',
                'birth_date' => '2015-01-01',
                'jersey_number' => 7,
            ])
            ->assertRedirect(route('teams.show', $team));

        $player = Player::query()->where('document_number', '111')->firstOrFail();
        $this->assertSame($team->id, $player->team_id);
        $this->assertSame(7, $player->jersey_number);
        $this->assertSame(0, DB::table('player_team')->where('player_id', $player->id)->count());
    }

    public function test_a_player_already_registered_gets_linked_instead_of_duplicated(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create();
        $firstTeam = $this->makeTeamForClub($club, 'Cebollita', null);
        $secondTeam = $this->makeTeamForClub($club, 'Infantil', null);

        $player = Player::factory()->create([
            'team_id' => $firstTeam->id,
            'document_number' => '222',
            'full_name' => 'Kid Original',
            'birth_date' => '2015-01-01',
        ]);

        $this->actingAs($user)
            ->post(route('teams.players.store', $secondTeam), [
                'document_number' => '222',
                'full_name' => 'Nombre Distinto Escrito Por Error',
                'jersey_number' => 10,
            ])
            ->assertRedirect(route('teams.show', $secondTeam));

        $this->assertSame(1, Player::query()->where('document_number', '222')->count(), 'no debe duplicarse');
        $this->assertTrue($player->fresh()->teams()->whereKey($secondTeam->id)->exists());
        $this->assertSame('KID ORIGINAL', $player->fresh()->full_name, 'no se sobreescribe con el nombre tipeado de nuevo');

        $pivotJersey = DB::table('player_team')->where('player_id', $player->id)->where('team_id', $secondTeam->id)->value('jersey_number');
        $this->assertSame(10, $pivotJersey);
    }

    public function test_linking_to_a_second_team_is_blocked_without_a_birth_date(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create();
        $firstTeam = $this->makeTeamForClub($club, 'Infantil', null);
        $secondTeam = $this->makeTeamForClub($club, 'Cebollita', null);

        $player = Player::factory()->create([
            'team_id' => $firstTeam->id,
            'document_number' => '333',
            'birth_date' => null,
        ]);

        $this->actingAs($user)
            ->post(route('teams.players.store', $secondTeam), [
                'document_number' => '333',
                'full_name' => 'Sin Fecha',
            ])
            ->assertSessionHasErrors('document_number');

        $this->assertFalse($player->fresh()->teams()->whereKey($secondTeam->id)->exists());
    }

    public function test_linking_to_a_younger_category_than_the_players_age_is_blocked(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create();
        $firstTeam = $this->makeTeamForClub($club, 'Infantil', 2015);
        // "Baby" here is a YOUNGER category (higher birth_year_to = born
        // more recently) than a player born in 2010.
        $babyTeam = $this->makeTeamForClub($club, 'Baby', 2021);

        $player = Player::factory()->create([
            'team_id' => $firstTeam->id,
            'document_number' => '444',
            'birth_date' => '2010-06-01',
        ]);

        $this->actingAs($user)
            ->post(route('teams.players.store', $babyTeam), [
                'document_number' => '444',
                'full_name' => 'Grandecito',
            ])
            ->assertSessionHasErrors('document_number');

        $this->assertFalse($player->fresh()->teams()->whereKey($babyTeam->id)->exists());
    }

    public function test_linking_to_an_older_category_is_allowed(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create();
        $firstTeam = $this->makeTeamForClub($club, 'Infantil', 2015);
        // "Juvenil" here is an OLDER category (lower birth_year_to) --
        // playing up is allowed.
        $juvenilTeam = $this->makeTeamForClub($club, 'Juvenil', 2008);

        $player = Player::factory()->create([
            'team_id' => $firstTeam->id,
            'document_number' => '555',
            'birth_date' => '2010-06-01',
        ]);

        $this->actingAs($user)
            ->post(route('teams.players.store', $juvenilTeam), [
                'document_number' => '555',
                'full_name' => 'Juega Arriba',
            ])
            ->assertRedirect(route('teams.show', $juvenilTeam));

        $this->assertTrue($player->fresh()->teams()->whereKey($juvenilTeam->id)->exists());
    }

    public function test_jersey_numbers_are_scoped_per_team_not_globally(): void
    {
        $user = User::factory()->create();
        $team = $this->makeTeam($user);

        $this->actingAs($user)
            ->post(route('teams.players.store', $team), [
                'full_name' => 'Uno',
                'jersey_number' => 9,
            ])
            ->assertRedirect();

        $this->actingAs($user)
            ->post(route('teams.players.store', $team), [
                'full_name' => 'Dos',
                'jersey_number' => 9,
            ])
            ->assertSessionHasErrors('jersey_number');
    }

    public function test_legacy_team_player_creation_is_unaffected(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create();
        $team = Team::factory()->for($tournament)->for($category)->create();

        $this->actingAs($user)
            ->post(route('teams.players.store', $team), [
                'full_name' => 'Legacy Player',
                'document_number' => '999',
                'jersey_number' => 5,
            ])
            ->assertRedirect(route('teams.show', $team));

        $player = Player::query()->where('document_number', '999')->firstOrFail();
        $this->assertSame($team->id, $player->team_id);
    }

    /**
     * Club-level enrollment (checkbox-per-plantel) -- lets one submission
     * put a brand-new player straight into more than one category at
     * once, per the client's explicit request.
     */
    public function test_club_level_enrollment_links_a_new_player_to_all_selected_teams_at_once(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create();
        // Infantil (2012) is the player's natural category; Pre-Juvenil
        // (2009, an OLDER category -- lower birth_year_to) is legitimately
        // playing up, both must be selectable in one go.
        $infantil = $this->makeTeamForClub($club, 'Infantil', 2012);
        $preJuvenil = $this->makeTeamForClub($club, 'Pre-Juvenil', 2009);

        $this->actingAs($user)
            ->post(route('clubs.players.store', $club), [
                'full_name' => 'Doble Categoria',
                'birth_date' => '2012-01-01',
                'team_ids' => [$infantil->id, $preJuvenil->id],
            ])
            ->assertRedirect(route('clubs.show', $club));

        $player = Player::query()->where('full_name', 'DOBLE CATEGORIA')->firstOrFail();
        // First selected team becomes the direct team_id, the rest go
        // through player_team -- see PlayerController::storeForClub().
        $this->assertSame($infantil->id, $player->team_id);
        $this->assertTrue($player->teams()->whereKey($preJuvenil->id)->exists());
    }

    public function test_club_level_enrollment_requires_a_birth_date(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create();
        $team = $this->makeTeamForClub($club, 'Infantil', null);

        $this->actingAs($user)
            ->post(route('clubs.players.store', $club), [
                'full_name' => 'Sin Fecha',
                'team_ids' => [$team->id],
            ])
            ->assertSessionHasErrors('birth_date');
    }

    public function test_club_level_enrollment_blocks_an_ineligible_category(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create();
        $baby = $this->makeTeamForClub($club, 'Baby', 2021);

        $this->actingAs($user)
            ->post(route('clubs.players.store', $club), [
                'full_name' => 'Grandecito',
                'birth_date' => '2010-01-01',
                'team_ids' => [$baby->id],
            ])
            ->assertSessionHasErrors('team_ids');

        $this->assertSame(0, Player::query()->where('full_name', 'Grandecito')->count());
    }

    public function test_club_level_enrollment_links_an_existing_player_without_touching_their_stored_data(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create();
        $firstTeam = $this->makeTeamForClub($club, 'Cebollita', 2017);
        $secondTeam = $this->makeTeamForClub($club, 'Infantil', 2012);

        $player = Player::factory()->create([
            'team_id' => $firstTeam->id,
            'full_name' => 'Ya Existe',
            'document_number' => '777',
            'birth_date' => '2012-01-01',
        ]);

        $this->actingAs($user)
            ->post(route('clubs.players.store', $club), [
                'document_number' => '777',
                'full_name' => 'Nombre Distinto',
                'birth_date' => '2012-01-01',
                'team_ids' => [$secondTeam->id],
            ])
            ->assertRedirect(route('clubs.show', $club));

        $this->assertSame(1, Player::query()->where('document_number', '777')->count());
        $this->assertSame('YA EXISTE', $player->fresh()->full_name);
        $this->assertTrue($player->fresh()->teams()->whereKey($secondTeam->id)->exists());
    }

    /**
     * The gap the client reported directly: completing a backfilled
     * player's birth_date from their edit page should immediately let the
     * organizer enroll them into another plantel of the same club, in
     * that same submission.
     */
    public function test_editing_a_player_to_add_their_birth_date_can_enroll_them_into_another_team_at_once(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create();
        $originalTeam = $this->makeTeamForClub($club, 'Infantil', 2012);
        $otherTeam = $this->makeTeamForClub($club, 'Pre-Juvenil', 2009);

        $player = Player::factory()->create([
            'team_id' => $originalTeam->id,
            'birth_date' => null,
        ]);

        $this->actingAs($user)
            ->put(route('players.update', $player), [
                'full_name' => $player->full_name,
                'birth_date' => '2012-01-01',
                'team_ids' => [$otherTeam->id],
            ])
            ->assertRedirect(route('teams.show', $originalTeam));

        $player->refresh();
        $this->assertNotNull($player->birth_date);
        $this->assertTrue($player->teams()->whereKey($otherTeam->id)->exists());
    }

    /**
     * Reported by the client: adding the birth_date of a player whose only
     * checked box was their own current plantel (rendered checked+disabled,
     * so it shouldn't be submitted at all -- see the edit view's comment)
     * still showed "no se pudo sumar a algún plantel elegido" -- a stray
     * resubmission of that same team_id. It's already on the player, so
     * there's nothing to reject: this must save cleanly with no error.
     */
    public function test_editing_a_player_submitting_their_own_current_team_id_is_not_an_error(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create();
        $originalTeam = $this->makeTeamForClub($club, 'Cebollita', 2017);

        $player = Player::factory()->create([
            'team_id' => $originalTeam->id,
            'birth_date' => null,
        ]);

        $this->actingAs($user)
            ->put(route('players.update', $player), [
                'full_name' => $player->full_name,
                'birth_date' => '2017-01-15',
                'team_ids' => [$originalTeam->id],
            ])
            ->assertRedirect(route('teams.show', $originalTeam))
            ->assertSessionDoesntHaveErrors();

        $this->assertNotNull($player->fresh()->birth_date);
    }

    public function test_editing_a_player_cannot_enroll_them_into_an_ineligible_category(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create();
        $originalTeam = $this->makeTeamForClub($club, 'Infantil', 2012);
        $babyTeam = $this->makeTeamForClub($club, 'Baby', 2021);

        $player = Player::factory()->create(['team_id' => $originalTeam->id, 'birth_date' => null]);

        $this->actingAs($user)
            ->put(route('players.update', $player), [
                'full_name' => $player->full_name,
                'birth_date' => '2012-01-01',
                'team_ids' => [$babyTeam->id],
            ])
            ->assertSessionHasErrors('team_ids');

        $this->assertFalse($player->fresh()->teams()->whereKey($babyTeam->id)->exists());
        // The rejected plantel must never roll back the birth_date that was
        // otherwise valid -- that's the whole point of checking team_ids
        // separately from the rest, see PlayerController::update().
        $this->assertNotNull($player->fresh()->birth_date);
    }

    public function test_editing_a_player_only_offers_teams_from_their_own_club(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create();
        $otherClub = Club::factory()->for($user)->create();
        $originalTeam = $this->makeTeamForClub($club, 'Infantil', 2012);
        $unrelatedTeam = $this->makeTeamForClub($otherClub, 'Infantil', 2012);

        $player = Player::factory()->create(['team_id' => $originalTeam->id, 'birth_date' => '2012-01-01']);

        $this->actingAs($user)
            ->put(route('players.update', $player), [
                'full_name' => $player->full_name,
                'birth_date' => '2012-01-01',
                'team_ids' => [$unrelatedTeam->id],
            ])
            ->assertSessionHasErrors('team_ids');

        $this->assertFalse($player->fresh()->teams()->whereKey($unrelatedTeam->id)->exists());
    }

    /**
     * Reported after a real user searched a player up, saw they were
     * listed under NUEVA ALIANZA · PRE-JUVENIL, but their edit page's
     * candidate checkboxes only showed TETERITO (a plantel they weren't on
     * yet) -- reading as if PRE-JUVENIL wasn't real. The candidates list
     * was always correct (it only ever lists what's missing); what was
     * missing was any confirmation of what the player already has. Fixed
     * by folding both into one list: an already-current plantel shows up
     * checked and labeled "(ya está)", right alongside the ones still
     * available to add.
     */
    public function test_the_edit_page_shows_every_plantel_the_player_is_already_on_as_a_checked_checkbox(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create(['name' => 'Nilmar']);
        $teamA = $this->makeTeamForClub($club, 'Infantil', 2012);
        $teamB = $this->makeTeamForClub($club, 'Cebollita', 2016);

        $player = Player::factory()->create(['team_id' => $teamA->id, 'birth_date' => '2016-01-01']);
        $player->teams()->attach($teamB->id);

        $response = $this->actingAs($user)->get(route('players.edit', $player));

        // Cebollita (birth_year_to 2016) is the YOUNGER of the two, so it
        // sorts before Infantil (2012) -- see Team::sortedByCategoryAge().
        $response->assertOk()
            ->assertSeeInOrder(['Planteles de este club', 'CEBOLLITA', 'INFANTIL'])
            ->assertSee($teamA->name.' (ya está)')
            ->assertSee($teamB->name.' (ya está)');
    }

    public function test_a_candidate_team_the_player_is_not_on_yet_has_no_ya_esta_label(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create(['name' => 'Nilmar']);
        $teamA = $this->makeTeamForClub($club, 'Infantil', 2012);
        $teamB = $this->makeTeamForClub($club, 'Cebollita', 2016);

        $player = Player::factory()->create(['team_id' => $teamA->id, 'birth_date' => '2016-01-01']);

        $response = $this->actingAs($user)->get(route('players.edit', $player));

        $response->assertOk()
            ->assertSee($teamA->name.' (ya está)')
            ->assertDontSee($teamB->name.' (ya está)');
    }

    // ── Quitar de un plantel puntual (PlayerController::detachTeam) ────────

    public function test_a_user_can_detach_a_player_from_an_extra_plantel(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create();
        $teamA = $this->makeTeamForClub($club, 'Infantil', null);
        $teamB = $this->makeTeamForClub($club, 'Cebollita', null);

        $player = Player::factory()->create(['team_id' => $teamA->id]);
        $player->teams()->attach($teamB->id);

        $this->actingAs($user)->delete(route('players.teams.destroy', [$player, $teamB]))
            ->assertRedirect(route('players.edit', $player));

        $this->assertFalse($player->teams()->whereKey($teamB->id)->exists());
        $this->assertSame($teamA->id, $player->fresh()->team_id);
    }

    public function test_a_user_cannot_detach_a_team_from_another_users_player(): void
    {
        $owner = User::factory()->create();
        $club = Club::factory()->for($owner)->create();
        $teamA = $this->makeTeamForClub($club, 'Infantil', null);
        $teamB = $this->makeTeamForClub($club, 'Cebollita', null);

        $player = Player::factory()->create(['team_id' => $teamA->id]);
        $player->teams()->attach($teamB->id);

        $intruder = User::factory()->create();

        $this->actingAs($intruder)->delete(route('players.teams.destroy', [$player, $teamB]))
            ->assertForbidden();

        $this->assertTrue($player->teams()->whereKey($teamB->id)->exists());
    }

    // ── Eliminar del club (PlayerController::destroyFromClub) ──────────────

    public function test_a_player_with_no_other_club_is_deleted_entirely_when_removed(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create();
        $team = $this->makeTeamForClub($club, 'Infantil', null);
        $player = Player::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user)->delete(route('clubs.players.destroy', [$club, $player]))
            ->assertRedirect(route('clubs.show', $club));

        $this->assertDatabaseMissing('players', ['id' => $player->id]);
    }

    public function test_a_player_still_belonging_to_another_club_is_reassigned_instead_of_deleted(): void
    {
        $user = User::factory()->create();
        $clubA = Club::factory()->for($user)->create();
        $clubB = Club::factory()->for($user)->create();
        $teamA = $this->makeTeamForClub($clubA, 'Infantil', null);
        $teamB = $this->makeTeamForClub($clubB, 'Cebollita', null);

        $player = Player::factory()->create(['team_id' => $teamA->id]);
        $player->teams()->attach($teamB->id);

        $this->actingAs($user)->delete(route('clubs.players.destroy', [$clubA, $player]))
            ->assertRedirect(route('clubs.show', $clubA));

        $player->refresh();
        $this->assertDatabaseHas('players', ['id' => $player->id]);
        $this->assertSame($teamB->id, $player->team_id);
        $this->assertFalse($player->teams()->whereKey($teamB->id)->exists(), 'the promoted team should no longer also be a pivot row');
    }

    public function test_removing_is_blocked_when_the_player_has_a_goal_registered_at_that_club(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create();
        $team = $this->makeTeamForClub($club, 'Infantil', null);
        $player = Player::factory()->create(['team_id' => $team->id]);

        MatchEvent::factory()->create([
            'team_id' => $team->id,
            'player_id' => $player->id,
            'type' => MatchEventType::Goal,
        ]);

        $this->actingAs($user)->delete(route('clubs.players.destroy', [$club, $player]))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('players', ['id' => $player->id]);
    }

    public function test_removing_is_blocked_when_the_player_has_a_sanction_at_that_club(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create();
        $team = $this->makeTeamForClub($club, 'Infantil', null);
        $player = Player::factory()->create(['team_id' => $team->id]);

        Sanction::factory()->create([
            'team_id' => $team->id,
            'player_id' => $player->id,
            'type' => SanctionType::RedCard,
        ]);

        $this->actingAs($user)->delete(route('clubs.players.destroy', [$club, $player]))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('players', ['id' => $player->id]);
    }

    public function test_removing_from_one_club_leaves_a_pivot_team_at_another_club_untouched(): void
    {
        $user = User::factory()->create();
        $clubA = Club::factory()->for($user)->create();
        $clubB = Club::factory()->for($user)->create();
        $teamA = $this->makeTeamForClub($clubA, 'Infantil', null);
        $teamB1 = $this->makeTeamForClub($clubA, 'Cebollita', null);
        $teamB2 = $this->makeTeamForClub($clubB, 'Pony', null);

        $player = Player::factory()->create(['team_id' => $teamA->id]);
        $player->teams()->attach([$teamB1->id, $teamB2->id]);

        $this->actingAs($user)->delete(route('clubs.players.destroy', [$clubA, $player]));

        $player->refresh();
        $this->assertSame($teamB2->id, $player->team_id);
        $this->assertFalse($player->teams()->whereKey($teamB1->id)->exists());
    }

    public function test_a_user_cannot_remove_a_player_from_another_users_club(): void
    {
        $owner = User::factory()->create();
        $club = Club::factory()->for($owner)->create();
        $team = $this->makeTeamForClub($club, 'Infantil', null);
        $player = Player::factory()->create(['team_id' => $team->id]);

        $intruder = User::factory()->create();

        $this->actingAs($intruder)->delete(route('clubs.players.destroy', [$club, $player]))
            ->assertForbidden();

        $this->assertDatabaseHas('players', ['id' => $player->id]);
    }

    // ── Un jugador solo puede estar en un club a la vez ─────────────────────

    public function test_registering_an_active_players_document_at_a_different_club_is_blocked(): void
    {
        $user = User::factory()->create();
        $clubA = Club::factory()->for($user)->create(['name' => 'Club A']);
        $clubB = Club::factory()->for($user)->create(['name' => 'Club B']);
        $teamA = $this->makeTeamForClub($clubA, 'Infantil', null);
        $teamB = $this->makeTeamForClub($clubB, 'Infantil', null);

        $player = Player::factory()->create(['team_id' => $teamA->id, 'document_number' => '888', 'is_active' => true]);

        $this->actingAs($user)
            ->post(route('teams.players.store', $teamB), [
                'document_number' => '888',
                'full_name' => 'Cualquier Nombre',
            ])
            ->assertSessionHasErrors('document_number');

        $this->assertSame($teamA->id, $player->fresh()->team_id);
        $this->assertFalse($player->fresh()->teams()->whereKey($teamB->id)->exists());
    }

    public function test_registering_an_inactive_players_document_at_a_different_club_moves_them(): void
    {
        $user = User::factory()->create();
        $clubA = Club::factory()->for($user)->create();
        $clubB = Club::factory()->for($user)->create();
        $teamA = $this->makeTeamForClub($clubA, 'Infantil', null);
        $teamAExtra = $this->makeTeamForClub($clubA, 'Cebollita', null);
        $teamB = $this->makeTeamForClub($clubB, 'Infantil', null);

        $player = Player::factory()->create(['team_id' => $teamA->id, 'document_number' => '999', 'is_active' => false, 'birth_date' => '2012-01-01']);
        $player->teams()->attach($teamAExtra->id);

        $this->actingAs($user)
            ->post(route('teams.players.store', $teamB), [
                'document_number' => '999',
                'full_name' => 'Cualquier Nombre',
            ])
            ->assertRedirect(route('teams.show', $teamB));

        $player->refresh();
        $this->assertSame($teamB->id, $player->team_id);
        $this->assertTrue($player->is_active);
        $this->assertFalse($player->teams()->whereKey($teamAExtra->id)->exists(), 'old club link should be dropped');
    }

    public function test_club_level_enrollment_blocks_an_active_player_from_another_club(): void
    {
        $user = User::factory()->create();
        $clubA = Club::factory()->for($user)->create(['name' => 'Club Origen']);
        $clubB = Club::factory()->for($user)->create();
        $teamA = $this->makeTeamForClub($clubA, 'Infantil', null);
        $teamB = $this->makeTeamForClub($clubB, 'Infantil', null);

        $player = Player::factory()->create(['team_id' => $teamA->id, 'document_number' => '1010', 'is_active' => true]);

        $this->actingAs($user)
            ->post(route('clubs.players.store', $clubB), [
                'document_number' => '1010',
                'full_name' => 'Cualquier Nombre',
                'birth_date' => '2012-01-01',
                'team_ids' => [$teamB->id],
            ])
            ->assertSessionHasErrors('document_number');

        $this->assertSame($teamA->id, $player->fresh()->team_id);
    }

    public function test_club_level_enrollment_moves_an_inactive_player_from_another_club(): void
    {
        $user = User::factory()->create();
        $clubA = Club::factory()->for($user)->create();
        $clubB = Club::factory()->for($user)->create();
        $teamA = $this->makeTeamForClub($clubA, 'Infantil', null);
        $teamB = $this->makeTeamForClub($clubB, 'Infantil', null);

        $player = Player::factory()->create(['team_id' => $teamA->id, 'document_number' => '1111', 'is_active' => false, 'birth_date' => '2012-01-01']);

        $this->actingAs($user)
            ->post(route('clubs.players.store', $clubB), [
                'document_number' => '1111',
                'full_name' => 'Cualquier Nombre',
                'birth_date' => '2012-01-01',
                'team_ids' => [$teamB->id],
            ])
            ->assertRedirect(route('clubs.show', $clubB));

        $player->refresh();
        $this->assertSame($teamB->id, $player->team_id);
        $this->assertTrue($player->is_active);
    }

    private function makeTeamForClub(Club $club, string $categoryName, ?int $birthYearTo): Team
    {
        $category = Category::factory()->create([
            'tournament_id' => null,
            'user_id' => $club->user_id,
            'name' => $categoryName,
            'uses_groups' => false,
            'birth_year_to' => $birthYearTo,
        ]);

        return Team::factory()->create([
            'club_id' => $club->id,
            'category_id' => $category->id,
            'tournament_id' => null,
            'group_id' => null,
        ]);
    }
}
