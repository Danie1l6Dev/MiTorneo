<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LoadsClubNameAliases;
use App\Services\CategoryTournamentPromotionService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * T02-01 of docs/plan-reestructuracion/02-unificacion-categorias-torneo.md.
 * Run with --dry-run first (always) and review the report before ever
 * running it for real -- see docs/plan-reestructuracion/00-estrategia-migracion-datos.md.
 */
class PromoteCategoriesToCatalog extends Command
{
    use LoadsClubNameAliases;

    protected $signature = 'tournaments:promote-categories-to-catalog
        {--dry-run : Solo mostrar el reporte, no escribir nada}
        {--aliases= : Ruta a un JSON {"<user_id>": {"nombre legacy": "nombre canónico del club"}} confirmado a mano -- nunca se adivina}
        {--ages= : Ruta a un JSON {"<user_id>": {"nombre categoría": {"birth_year_from":Y,"birth_year_to":Y}}} confirmado a mano}';

    protected $description = 'Promueve cada categoría/grupo/plantel propio de un torneo al catálogo global del organizador, en el mismo lugar (mismos ids)';

    public function handle(CategoryTournamentPromotionService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $aliases = $this->loadClubNameAliases();
        $ageRanges = $this->loadAgeRanges();
        if ($aliases === null || $ageRanges === null) {
            return self::FAILURE;
        }

        if (! $dryRun && ! $this->confirm('Esto va a ESCRIBIR en la base de datos actual (promueve categorías/grupos/planteles al catálogo, en el lugar). ¿Seguro que ya revisaste el reporte de --dry-run y confirmas continuar?', false)) {
            $this->warn('Cancelado.');

            return self::SUCCESS;
        }

        $report = $service->run(execute: ! $dryRun, clubNameAliases: $aliases, ageRanges: $ageRanges);

        $this->printReport($report, $dryRun);

        return self::SUCCESS;
    }

    /**
     * @param  array{categories: Collection, groups: Collection, clubs: Collection, teams: Collection}  $report
     */
    private function printReport(array $report, bool $dryRun): void
    {
        $this->newLine();
        $this->line($dryRun
            ? '<comment>MODO REPORTE (--dry-run) — no se escribió nada todavía.</comment>'
            : '<info>Ejecutado — los cambios de arriba ya se aplicaron.</info>');

        $this->newLine();
        $this->line('== Categorías promovidas ==');
        $this->table(['ID', 'Usuario', 'Nombre original', 'Nombre final', '¿Desambiguada?', 'Torneo de origen', 'Rango de edad', 'Acción'], $report['categories']->map(fn (array $r) => [
            $r['id'], $r['user_id'], $r['original_name'], $r['final_name'], $r['disambiguated'] ? 'SÍ' : '-', $r['tournament'], $r['birth_years'] ?? '-', $r['action'],
        ])->all());

        $disambiguated = $report['categories']->filter(fn (array $r) => $r['disambiguated']);
        if ($disambiguated->isNotEmpty()) {
            $this->warn("⚠ {$disambiguated->count()} categoría(s) ya tenían una categoría global con el mismo nombre -- se promovieron con nombre desambiguado en vez de fusionarse. Revisa si en realidad son la misma categoría (y renómbralas a mano después) o son legítimamente distintas.");
        }

        $this->newLine();
        $this->line('== Grupos promovidos ==');
        $this->table(['ID', 'Categoría', 'Nombre', 'Acción'], $report['groups']->map(fn (array $r) => [
            $r['id'], $r['category'], $r['name'], $r['action'],
        ])->all());

        $this->newLine();
        $this->line('== Clubes ==');
        $this->table(['Usuario', 'Nombre', 'Acción', 'ID'], $report['clubs']->map(fn (array $r) => [
            $r['user_id'], $r['name'], $r['action'], $r['id'] ?? '-',
        ])->all());

        $this->newLine();
        $this->line('== Planteles promovidos ==');
        $this->table(['ID', 'Club', 'Nombre plantel', 'Categoría', 'Torneo de origen', 'Acción'], $report['teams']->map(fn (array $r) => [
            $r['id'], $r['club'], $r['squad_name'], $r['category'], $r['tournament'], $r['action'],
        ])->all());
    }
}
