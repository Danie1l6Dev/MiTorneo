<?php

namespace Tests\Feature\Services;

use App\Models\Category;
use App\Models\Club;
use App\Models\Group;
use App\Models\Player;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Services\GlobalCatalogBackfillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Locks in the merge/identity rules from
 * docs/plan-reestructuracion/01-clubes-equipos-categorias-globales.md
 * (decisions #2, #3, #6, #7) so a future change to the service can't
 * silently drift from what was actually agreed with the client.
 */
class GlobalCatalogBackfillServiceTest extends TestCase
{
    use RefreshDatabase;

    private GlobalCatalogBackfillService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new GlobalCatalogBackfillService;
    }

    public function test_dry_run_never_writes_to_the_database(): void
    {
        $tournament = Tournament::factory()->create();
        Category::factory()->for($tournament)->create(['name' => 'Baby']);

        $this->service->run(execute: false);

        $this->assertSame(0, Club::query()->count());
        $this->assertSame(0, Category::query()->whereNull('tournament_id')->count());
        $this->assertSame(0, DB::table('tournament_category')->count());
    }

    public function test_it_merges_same_name_categories_for_the_same_organizer(): void
    {
        $organizer = User::factory()->create();
        $tournamentA = Tournament::factory()->for($organizer)->create();
        $tournamentB = Tournament::factory()->for($organizer)->create();

        $categoryA = Category::factory()->for($tournamentA)->create(['name' => 'Baby']);
        $categoryB = Category::factory()->for($tournamentB)->create(['name' => 'Baby']);

        $this->service->run(execute: true);

        $global = Category::query()->whereNull('tournament_id')->where('user_id', $organizer->id)->where('name', 'BABY')->get();
        $this->assertCount(1, $global, 'debe existir una sola categoría global "Baby" para este organizador');

        $this->assertTrue($tournamentA->globalCategories()->whereKey($global->first()->id)->exists());
        $this->assertTrue($tournamentB->globalCategories()->whereKey($global->first()->id)->exists());

        // Legacy rows untouched.
        $this->assertNotNull($categoryA->fresh()->tournament_id);
        $this->assertNotNull($categoryB->fresh()->tournament_id);
    }

    public function test_it_does_not_merge_same_name_categories_across_different_organizers(): void
    {
        $daniel = User::factory()->create();
        $fulano = User::factory()->create();

        Category::factory()->for(Tournament::factory()->for($daniel)->create())->create(['name' => 'Baby']);
        Category::factory()->for(Tournament::factory()->for($fulano)->create())->create(['name' => 'Baby']);

        $this->service->run(execute: true);

        $this->assertSame(2, Category::query()->whereNull('tournament_id')->where('name', 'BABY')->count());
    }

    /**
     * Plan decision #6: the same club fielding the same category+group in
     * more than one tournament is ONE global Team, not one per tournament
     * -- with every player from every legacy roster linked to it.
     */
    public function test_it_merges_the_same_club_category_group_across_tournaments(): void
    {
        $organizer = User::factory()->create();

        [$teamApertura, $playerApertura] = $this->makeClubTeamWithPlayer($organizer, 'Nilmar', 'Infantil', 'Grupo A', 'Juan Pérez');
        [$teamClausura, $playerClausura] = $this->makeClubTeamWithPlayer($organizer, 'Nilmar', 'Infantil', 'Grupo A', 'Pedro Gómez');

        $this->service->run(execute: true);

        $this->assertSame(1, Club::query()->where('user_id', $organizer->id)->where('name', 'NILMAR')->count());

        $globalTeams = Team::query()->whereNull('tournament_id')->get();
        $this->assertCount(1, $globalTeams, 'los dos planteles "Nilmar" (mismo club+categoría+grupo) deben fusionarse en uno solo');

        $globalTeam = $globalTeams->first();
        $this->assertTrue($globalTeam->tournaments()->whereKey($teamApertura->tournament_id)->exists());
        $this->assertTrue($globalTeam->tournaments()->whereKey($teamClausura->tournament_id)->exists());

        // Both players (one originally from each legacy tournament roster)
        // end up linked to the SAME global team.
        $this->assertTrue($playerApertura->fresh()->teams()->whereKey($globalTeam->id)->exists());
        $this->assertTrue($playerClausura->fresh()->teams()->whereKey($globalTeam->id)->exists());
        $this->assertSame(2, $globalTeam->globalPlayers()->count());
    }

    public function test_it_does_not_merge_the_same_club_name_across_different_categories_or_groups(): void
    {
        $organizer = User::factory()->create();

        $this->makeClubTeamWithPlayer($organizer, 'Nilmar', 'Cebollita', null, 'Kid Uno');
        $this->makeClubTeamWithPlayer($organizer, 'Nilmar', 'Infantil', 'Grupo A', 'Kid Dos');
        $this->makeClubTeamWithPlayer($organizer, 'Nilmar', 'Infantil', 'Grupo B', 'Kid Tres');

        $this->service->run(execute: true);

        $this->assertSame(1, Club::query()->where('name', 'NILMAR')->count(), 'un solo club Nilmar');
        $this->assertSame(3, Team::query()->whereNull('tournament_id')->count(), 'tres planteles distintos: Cebollita, Infantil-A, Infantil-B');
    }

    public function test_it_never_auto_merges_clubs_with_different_names_even_if_related(): void
    {
        $organizer = User::factory()->create();

        $this->makeClubTeamWithPlayer($organizer, 'Nilmar (A)', 'Infantil', 'Grupo A', 'Kid A');
        $this->makeClubTeamWithPlayer($organizer, 'Nilmar (B)', 'Infantil', 'Grupo B', 'Kid B');

        $report = $this->service->run(execute: true);

        $this->assertSame(2, Club::query()->count(), '"Nilmar (A)" y "Nilmar (B)" nunca se fusionan solos');
        $this->assertNotEmpty(
            $report['name_family_hints']->all(),
            'debe avisar que podrían estar relacionados, sin tocarlos'
        );
    }

    /**
     * Real case found in production data: "Baby" has no real sub-groups
     * (uses_groups = false), but a club can still field two separate
     * squads in it because one roster doesn't fit every kid -- named
     * "NILMAR (A)" / "NILMAR (B)" only as a manual label, not a real
     * Group. Once a human confirms (via $clubNameAliases) that both
     * belong to the same Club, the two squads must NOT collapse into one
     * Team just because club+category+group now match.
     */
    public function test_aliasing_two_squad_names_to_the_same_club_keeps_them_as_separate_teams(): void
    {
        $organizer = User::factory()->create();

        [$teamA, $playerA] = $this->makeClubTeamWithPlayer($organizer, 'NILMAR (A)', 'Baby', null, 'Kid A');
        [$teamB, $playerB] = $this->makeClubTeamWithPlayer($organizer, 'NILMAR (B)', 'Baby', null, 'Kid B');

        $report = $this->service->run(execute: true, clubNameAliases: [
            $organizer->id => ['NILMAR (A)' => 'NILMAR', 'NILMAR (B)' => 'NILMAR'],
        ]);

        $this->assertSame(1, Club::query()->where('name', 'NILMAR')->count(), 'un solo club NILMAR');

        $globalTeams = Team::query()->whereNull('tournament_id')->get();
        $this->assertCount(2, $globalTeams, 'las dos plantillas de Baby deben seguir siendo planteles separados');

        $this->assertTrue($playerA->fresh()->teams()->whereKey($globalTeams->firstWhere('name', 'NILMAR (A)')->id)->exists());
        $this->assertTrue($playerB->fresh()->teams()->whereKey($globalTeams->firstWhere('name', 'NILMAR (B)')->id)->exists());

        // Every squad still shows its own single player -- nothing merged.
        foreach ($globalTeams as $team) {
            $this->assertSame(1, $team->globalPlayers()->count());
        }

        $clubRow = $report['clubs']->firstWhere('name', 'NILMAR');
        $this->assertNotNull($clubRow, 'el reporte debe mostrar el nombre canónico, no el crudo');
    }

    public function test_it_copies_the_legacy_jersey_number_onto_the_player_team_pivot(): void
    {
        $organizer = User::factory()->create();
        [$team, $player] = $this->makeClubTeamWithPlayer($organizer, 'Nilmar', 'Infantil', null, 'Juan Pérez', jerseyNumber: 10);

        $this->service->run(execute: true);

        $globalTeam = Team::query()->whereNull('tournament_id')->firstOrFail();
        $pivot = DB::table('player_team')->where('player_id', $player->id)->where('team_id', $globalTeam->id)->first();

        $this->assertNotNull($pivot);
        $this->assertSame(10, $pivot->jersey_number);
    }

    public function test_running_it_twice_does_not_duplicate_anything(): void
    {
        $organizer = User::factory()->create();
        $this->makeClubTeamWithPlayer($organizer, 'Nilmar', 'Infantil', 'Grupo A', 'Juan Pérez');
        $this->makeClubTeamWithPlayer($organizer, 'Nilmar', 'Infantil', 'Grupo A', 'Pedro Gómez');

        $this->service->run(execute: true);
        $firstRunCounts = [
            'categories' => Category::query()->whereNull('tournament_id')->count(),
            'clubs' => Club::query()->count(),
            'teams' => Team::query()->whereNull('tournament_id')->count(),
            'player_team' => DB::table('player_team')->count(),
        ];

        $this->service->run(execute: true);
        $secondRunCounts = [
            'categories' => Category::query()->whereNull('tournament_id')->count(),
            'clubs' => Club::query()->count(),
            'teams' => Team::query()->whereNull('tournament_id')->count(),
            'player_team' => DB::table('player_team')->count(),
        ];

        $this->assertSame($firstRunCounts, $secondRunCounts);
    }

    /**
     * $ageRanges (e.g. an official age-eligibility table like
     * docs/plan-reestructuracion/faudis-rango-edades-2026.json) has no
     * source in the legacy schema at all -- it only ever comes from this
     * explicit, human-confirmed input.
     */
    public function test_it_applies_a_confirmed_age_range_to_a_newly_created_category(): void
    {
        $organizer = User::factory()->create();
        Category::factory()->for(Tournament::factory()->for($organizer)->create())->create(['name' => 'Baby']);

        // Keyed against $category->name as actually stored -- already
        // uppercased by NormalizesToUppercase by the time the service reads
        // it.
        $this->service->run(execute: true, ageRanges: [
            $organizer->id => ['BABY' => ['birth_year_from' => 2020, 'birth_year_to' => 2021]],
        ]);

        $category = Category::query()->whereNull('tournament_id')->where('name', 'BABY')->firstOrFail();
        $this->assertSame(2020, $category->birth_year_from);
        $this->assertSame(2021, $category->birth_year_to);
    }

    /**
     * Self-healing: an organizer might only get their official age table
     * after the catalog already exists (exactly what happened with
     * Faudis) -- re-running with it later should still apply it.
     */
    public function test_it_backfills_an_age_range_onto_an_already_existing_category(): void
    {
        $organizer = User::factory()->create();
        Category::factory()->for(Tournament::factory()->for($organizer)->create())->create(['name' => 'Baby']);
        $this->service->run(execute: true); // first pass, no ages file yet

        $this->service->run(execute: true, ageRanges: [
            $organizer->id => ['BABY' => ['birth_year_from' => 2020, 'birth_year_to' => 2021]],
        ]);

        $category = Category::query()->whereNull('tournament_id')->where('name', 'BABY')->firstOrFail();
        $this->assertSame(2020, $category->birth_year_from);
        $this->assertSame(2021, $category->birth_year_to);
    }

    /**
     * A player created after the app already cut over to the new schema
     * (via PlayerController::storeForTeam(), see
     * docs/plan-reestructuracion/01-clubes-equipos-categorias-globales.md)
     * has team_id pointing straight at a global Team and no player_team
     * row for it -- that must never be reported as something the backfill
     * still needs to fix.
     */
    public function test_a_player_already_pointing_at_a_global_team_is_never_flagged_as_unresolved(): void
    {
        $organizer = User::factory()->create();
        [$team, $player] = $this->makeClubTeamWithPlayer($organizer, 'Nilmar', 'Infantil', null, 'Ya Global');
        $this->service->run(execute: true); // creates the canonical global Team

        $globalTeam = Team::query()->whereNull('tournament_id')->firstOrFail();
        $newPlayer = Player::factory()->create(['team_id' => $globalTeam->id]);

        $report = $this->service->run(execute: false);

        $row = $report['player_links']->firstWhere('player_id', $newPlayer->id);
        $this->assertNotNull($row);
        $this->assertSame('ya vinculado', $row['action']);
    }

    /**
     * @return array{0: Team, 1: Player}
     */
    private function makeClubTeamWithPlayer(
        User $organizer,
        string $teamName,
        string $categoryName,
        ?string $groupName,
        string $playerName,
        ?int $jerseyNumber = null,
    ): array {
        $tournament = Tournament::factory()->for($organizer)->create();
        $category = Category::factory()->for($tournament)->create([
            'name' => $categoryName,
            'uses_groups' => $groupName !== null,
        ]);
        $group = $groupName
            ? Group::factory()->for($category)->create(['name' => $groupName, 'tournament_id' => $tournament->id])
            : null;

        $team = Team::factory()->for($category)->for($tournament)->create([
            'name' => $teamName,
            'group_id' => $group?->id,
        ]);

        $player = Player::factory()->for($team)->create([
            'full_name' => $playerName,
            'jersey_number' => $jerseyNumber,
        ]);

        return [$team, $player];
    }
}
