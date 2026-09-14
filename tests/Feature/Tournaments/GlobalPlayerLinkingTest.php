<?php

namespace Tests\Feature\Tournaments;

use App\Models\Category;
use App\Models\Club;
use App\Models\Player;
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
        $firstTeam = $this->makeTeam($user, 'Cebollita');
        $secondTeam = $this->makeTeam($user, 'Infantil');

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
        $firstTeam = $this->makeTeam($user);
        $secondTeam = $this->makeTeam($user);

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
        $firstTeam = $this->makeTeam($user, 'Infantil', birthYearTo: 2015);
        // "Baby" here is a YOUNGER category (higher birth_year_to = born
        // more recently) than a player born in 2010.
        $babyTeam = $this->makeTeam($user, 'Baby', birthYearTo: 2021);

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
        $firstTeam = $this->makeTeam($user, 'Infantil', birthYearTo: 2015);
        // "Juvenil" here is an OLDER category (lower birth_year_to) --
        // playing up is allowed.
        $juvenilTeam = $this->makeTeam($user, 'Juvenil', birthYearTo: 2008);

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
            ->assertSessionHasErrors('team_ids.0');
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
