<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LoadsClubNameAliases;
use App\Services\GlobalCatalogBackfillService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * T01-15/T01-16 of docs/plan-reestructuracion/01-clubes-equipos-categorias-globales.md.
 * Run with --dry-run first (always) and review the report before ever
 * running it for real -- see docs/plan-reestructuracion/00-estrategia-migracion-datos.md.
 */
class BackfillGlobalCatalog extends Command
{
    use LoadsClubNameAliases;

    protected $signature = 'tournaments:backfill-global-catalog
        {--dry-run : Solo mostrar el reporte, no escribir nada}
        {--aliases= : Ruta a un JSON {"<user_id>": {"nombre legacy": "nombre canónico del club"}} confirmado a mano -- nunca se adivina}
        {--ages= : Ruta a un JSON {"<user_id>": {"nombre categoría": {"birth_year_from":Y,"birth_year_to":Y}}} confirmado a mano (ej. una tabla oficial de edades) -- nunca se adivina}';

    protected $description = 'Puebla el catálogo global (Category/Group/Club/Team + player_team) a partir de los datos legacy por torneo';

    public function handle(GlobalCatalogBackfillService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $aliases = $this->loadClubNameAliases();
        $ageRanges = $this->loadAgeRanges();
        if ($aliases === null || $ageRanges === null) {
            return self::FAILURE;
        }

        if (! $dryRun && ! $this->confirm('Esto va a ESCRIBIR en la base de datos actual. ¿Seguro que ya revisaste el reporte de --dry-run y confirmas continuar?', false)) {
            $this->warn('Cancelado.');

            return self::SUCCESS;
        }

        $report = $service->run(execute: ! $dryRun, clubNameAliases: $aliases, ageRanges: $ageRanges);

        $this->printReport($report, $dryRun);

        return self::SUCCESS;
    }

    /**
     * @param  array{categories: Collection, groups: Collection, clubs: Collection, teams: Collection, player_links: Collection, name_family_hints: Collection}  $report
     */
    private function printReport(array $report, bool $dryRun): void
    {
        $this->newLine();
        $this->line($dryRun
            ? '<comment>MODO REPORTE (--dry-run) — no se escribió nada todavía.</comment>'
            : '<info>Ejecutado — los cambios de arriba ya se aplicaron.</info>');

        $this->newLine();
        $this->line('== Categorías globales ==');
        $this->table(['Usuario', 'Nombre', 'Acción', 'ID', 'Rango de edad', 'Torneos de origen'], $report['categories']->map(fn (array $r) => [
            $r['user_id'], $r['name'], $r['action'], $r['canonical_id'] ?? '-', $r['birth_years'] ?? '-', implode(', ', $r['tournaments']),
        ])->all());

        $this->newLine();
        $this->line('== Grupos globales ==');
        $this->table(['Categoría', 'Nombre', 'Acción', 'ID'], $report['groups']->map(fn (array $r) => [
            $r['category'], $r['name'], $r['action'], $r['canonical_id'] ?? '-',
        ])->all());

        $this->newLine();
        $this->line('== Clubes globales ==');
        $this->table(['Usuario', 'Nombre', 'Acción', 'ID'], $report['clubs']->map(fn (array $r) => [
            $r['user_id'], $r['name'], $r['action'], $r['canonical_id'] ?? '-',
        ])->all());

        $this->newLine();
        $this->line('== Planteles (Team) globales ==');
        $this->line('(Nombre plantel = el nombre legacy tal cual estaba cargado; puede repetir Club cuando ese club fielda más de una plantilla en la misma categoría/grupo.)');
        $this->table(['Club', 'Nombre plantel', 'Categoría', 'Grupo', 'Acción', 'ID', '¿Fusión?', 'Torneos de origen'], $report['teams']->map(fn (array $r) => [
            $r['club'], $r['squad_name'], $r['category'], $r['group'] ?? '-', $r['action'], $r['canonical_id'] ?? '-',
            $r['is_merge'] ? 'SÍ' : '-', implode(', ', $r['merged_from_tournaments']),
        ])->all());

        $merges = $report['teams']->filter(fn (array $r) => $r['is_merge']);
        if ($merges->isNotEmpty()) {
            $this->warn("⚠ {$merges->count()} plantel(es) fusionan jugadores de más de un torneo en un solo plantel global -- revisa bien esta tabla antes de correr sin --dry-run.");
        }

        $this->newLine();
        $this->line('== Vínculos jugador↔plantel (player_team) ==');
        $unresolved = $report['player_links']->filter(fn (array $r) => str_starts_with($r['action'], 'unresolved'));
        $this->line("Total jugadores: {$report['player_links']->count()} — sin resolver: {$unresolved->count()}");
        if ($unresolved->isNotEmpty()) {
            $this->table(['Jugador', 'ID', 'team_id legacy'], $unresolved->map(fn (array $r) => [
                $r['player'], $r['player_id'], $r['legacy_team_id'],
            ])->all());
        }

        if ($report['name_family_hints']->isNotEmpty()) {
            $this->newLine();
            $this->warn('== Posibles clubes relacionados (NO se fusionan solos, solo aviso) ==');
            $this->table(['Usuario', 'Posible nombre base', 'Nombres encontrados'], $report['name_family_hints']->map(fn (array $r) => [
                $r['user_id'], $r['possible_base_name'], implode(', ', $r['club_names']),
            ])->all());
        }
    }
}
