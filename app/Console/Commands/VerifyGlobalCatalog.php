<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LoadsClubNameAliases;
use App\Models\Player;
use App\Models\Tournament;
use App\Services\GlobalCatalogBackfillService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * T01-19 of docs/plan-reestructuracion/01-clubes-equipos-categorias-globales.md.
 * Run after tournaments:backfill-global-catalog (without --dry-run) to
 * confirm nothing was lost before the app cuts over (T01-20) to reading
 * the new schema. Exits non-zero if anything looks off.
 *
 * Two independent checks, on purpose:
 * 1. Re-running the backfill in --dry-run mode after a real run should be
 *    a total no-op (everything "reutilizado"/"ya vinculado") -- if
 *    anything still wants to be created or linked, the earlier run missed
 *    it.
 * 2. Direct per-tournament counts (legacy vs global), which catches
 *    problems the report-diff alone might not phrase clearly (e.g. an
 *    accidental merge that shouldn't have happened).
 */
class VerifyGlobalCatalog extends Command
{
    use LoadsClubNameAliases;

    protected $signature = 'tournaments:verify-global-catalog
        {--aliases= : Debe ser el MISMO archivo pasado al backfill real -- si no, cada club aliasado se reporta como "faltante"}
        {--ages= : Debe ser el MISMO archivo de rangos de edad pasado al backfill real}';

    protected $description = 'Verifica que el backfill del catálogo global no haya perdido categorías/planteles/jugadores de ningún torneo';

    public function handle(GlobalCatalogBackfillService $service): int
    {
        $aliases = $this->loadClubNameAliases();
        $ageRanges = $this->loadAgeRanges();
        if ($aliases === null || $ageRanges === null) {
            return self::FAILURE;
        }

        $problems = [];

        $problems = [...$problems, ...$this->checkBackfillIsNoOp($service, $aliases, $ageRanges)];
        $problems = [...$problems, ...$this->checkPerTournamentCounts()];
        $problems = [...$problems, ...$this->checkNoOrphanPlayers()];

        if ($problems === []) {
            $this->info('✅ Todo verificado -- el catálogo global refleja exactamente los datos legacy. Listo para T01-20 (Cortar).');

            return self::SUCCESS;
        }

        $this->error('❌ Se encontraron '.count($problems).' problema(s):');
        foreach ($problems as $problem) {
            $this->line("  - {$problem}");
        }

        return self::FAILURE;
    }

    /**
     * @param  array<int, array<string, string>>  $aliases
     * @param  array<int, array<string, array{birth_year_from?: int|null, birth_year_to?: int|null}>>  $ageRanges
     * @return list<string>
     */
    private function checkBackfillIsNoOp(GlobalCatalogBackfillService $service, array $aliases, array $ageRanges = []): array
    {
        $report = $service->run(execute: false, clubNameAliases: $aliases, ageRanges: $ageRanges);
        $problems = [];

        foreach (['categories', 'groups', 'clubs', 'teams'] as $section) {
            foreach ($report[$section] as $row) {
                if ($row['action'] !== 'reutilizado') {
                    $problems[] = "{$section}: fila sin backfillear -- ".json_encode($row, JSON_UNESCAPED_UNICODE);
                }
            }
        }

        foreach ($report['player_links'] as $row) {
            if ($row['action'] !== 'ya vinculado') {
                $problems[] = "player_links: {$row['player']} (id {$row['player_id']}) -- {$row['action']}";
            }
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private function checkPerTournamentCounts(): array
    {
        $problems = [];

        foreach (Tournament::query()->withCount(['categories', 'teams'])->get() as $tournament) {
            $globalCategoryCount = $tournament->globalCategories()->count();
            if ($globalCategoryCount !== $tournament->categories_count) {
                $problems[] = "Torneo \"{$tournament->name}\" (#{$tournament->id}): {$tournament->categories_count} categorías legacy vs {$globalCategoryCount} vinculadas globalmente";
            }

            $globalTeamCount = $tournament->globalTeams()->count();
            if ($globalTeamCount !== $tournament->teams_count) {
                $problems[] = "Torneo \"{$tournament->name}\" (#{$tournament->id}): {$tournament->teams_count} planteles legacy vs {$globalTeamCount} vinculados globalmente";
            }
        }

        return $problems;
    }

    /**
     * A player is fine either way: linked via player_team, OR with
     * team_id already pointing straight at a global Team (its only team
     * so far -- see PlayerController::storeForTeam(), which never
     * bothers with player_team for a first/only link). Only a player
     * whose team_id still points at a LEGACY (per-tournament) team and
     * has no player_team row at all is a real backfill miss.
     *
     * @return list<string>
     */
    private function checkNoOrphanPlayers(): array
    {
        $problems = [];

        $linkedPlayerIds = DB::table('player_team')->pluck('player_id')->unique();

        foreach (Player::query()->whereNotNull('team_id')->with('team')->get() as $player) {
            if ($player->team && $player->team->tournament_id === null) {
                continue;
            }

            if (! $linkedPlayerIds->contains($player->id)) {
                $problems[] = "Jugador \"{$player->full_name}\" (#{$player->id}) no tiene ningún vínculo player_team";
            }
        }

        return $problems;
    }
}
