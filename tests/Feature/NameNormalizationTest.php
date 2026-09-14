<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Club;
use App\Models\Coach;
use App\Models\CompetitionPhase;
use App\Models\Group;
use App\Models\Player;
use App\Models\Referee;
use App\Models\Team;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * App\Models\Concerns\NormalizesToUppercase: every identity field a user
 * types by hand (club/team/category/player/DT/referee/tournament/group/
 * phase name) is stored uppercase, so listings, brackets and the public
 * portal read consistently no matter how it was typed. Optional/free-text
 * fields (descriptions, seasons, sanction reasons, document numbers) are
 * deliberately left exactly as typed -- see each test below.
 */
class NameNormalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_clubs_name_is_uppercased_on_save(): void
    {
        $club = Club::factory()->create(['name' => 'Nilmar FC']);

        $this->assertSame('NILMAR FC', $club->fresh()->name);
    }

    public function test_a_teams_name_and_short_name_are_uppercased_on_save(): void
    {
        $team = Team::factory()->create(['name' => 'Real Norte (A)', 'short_name' => 'rno']);

        $team->refresh();
        $this->assertSame('REAL NORTE (A)', $team->name);
        $this->assertSame('RNO', $team->short_name);
    }

    public function test_a_categorys_name_is_uppercased_but_its_description_is_not(): void
    {
        $category = Category::factory()->create([
            'name' => 'Sub-10',
            'description' => 'Categoría para niños nacidos en 2016 o después',
        ]);

        $category->refresh();
        $this->assertSame('SUB-10', $category->name);
        $this->assertSame('Categoría para niños nacidos en 2016 o después', $category->description);
    }

    public function test_a_players_full_name_is_uppercased_but_their_document_number_is_not(): void
    {
        $player = Player::factory()->create(['full_name' => 'Juan Pérez', 'document_number' => 'v-12345678']);

        $player->refresh();
        $this->assertSame('JUAN PÉREZ', $player->full_name);
        $this->assertSame('v-12345678', $player->document_number);
    }

    public function test_a_coachs_full_name_is_uppercased_but_their_document_number_is_not(): void
    {
        $coach = Coach::factory()->create(['full_name' => 'Carlos Gómez', 'document_number' => 'v-87654321']);

        $coach->refresh();
        $this->assertSame('CARLOS GÓMEZ', $coach->full_name);
        $this->assertSame('v-87654321', $coach->document_number);
    }

    public function test_a_referees_full_name_is_uppercased(): void
    {
        $referee = Referee::factory()->create(['full_name' => 'Ana Ruiz']);

        $this->assertSame('ANA RUIZ', $referee->fresh()->full_name);
    }

    public function test_a_tournaments_name_is_uppercased_but_its_description_and_season_are_not(): void
    {
        $tournament = Tournament::factory()->create([
            'name' => 'Copa Verano',
            'description' => 'Torneo relámpago de fin de año',
            'season' => '2026-A',
        ]);

        $tournament->refresh();
        $this->assertSame('COPA VERANO', $tournament->name);
        $this->assertSame('Torneo relámpago de fin de año', $tournament->description);
        $this->assertSame('2026-A', $tournament->season);
    }

    public function test_a_groups_name_is_uppercased(): void
    {
        $group = Group::factory()->create(['name' => 'Grupo a']);

        $this->assertSame('GRUPO A', $group->fresh()->name);
    }

    public function test_a_competition_phases_name_is_uppercased(): void
    {
        $phase = CompetitionPhase::factory()->create(['name' => 'Fase de grupos']);

        $this->assertSame('FASE DE GRUPOS', $phase->fresh()->name);
    }

    public function test_uppercasing_is_reapplied_on_every_update_not_just_on_create(): void
    {
        $club = Club::factory()->create(['name' => 'Nombre Original']);

        $club->update(['name' => 'Nombre Actualizado']);

        $this->assertSame('NOMBRE ACTUALIZADO', $club->fresh()->name);
    }

    // ── Comando de backfill ──────────────────────────────────────────────

    public function test_the_backfill_command_normalizes_existing_lowercase_rows(): void
    {
        $club = Club::factory()->create(['name' => 'Placeholder']);
        // Writes the raw lowercase value straight to the DB, bypassing the
        // trait entirely -- simulating a row saved before it existed.
        DB::table('clubs')->where('id', $club->id)->update(['name' => 'nilmar fc']);

        $this->artisan('app:normalize-names-to-uppercase')->assertSuccessful();

        $this->assertSame('NILMAR FC', $club->fresh()->name);
    }

    public function test_the_backfill_commands_dry_run_does_not_write_anything(): void
    {
        $club = Club::factory()->create(['name' => 'Placeholder']);
        DB::table('clubs')->where('id', $club->id)->update(['name' => 'nilmar fc']);

        $this->artisan('app:normalize-names-to-uppercase --dry-run')->assertSuccessful();

        $this->assertSame('nilmar fc', $club->fresh()->name);
    }

    public function test_the_backfill_command_is_idempotent_on_already_uppercase_data(): void
    {
        Club::factory()->create(['name' => 'Ya Mayúscula']);

        $this->artisan('app:normalize-names-to-uppercase')->assertSuccessful();
        $this->artisan('app:normalize-names-to-uppercase')->assertSuccessful();

        $this->assertSame(1, Club::query()->where('name', 'YA MAYÚSCULA')->count());
    }
}
