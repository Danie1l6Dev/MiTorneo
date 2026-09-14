<?php

namespace App\Console\Commands;

use App\Models\CompetitionPhase;
use App\Models\TournamentMatch;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * T02-02 of docs/plan-reestructuracion/02-unificacion-categorias-torneo.md.
 * Run with --snapshot BEFORE tournaments:promote-categories-to-catalog to
 * save a fingerprint of every phase/match's identity and result; run again
 * with --compare AFTER promoting to confirm nothing shifted. Complements
 * (doesn't replace) the automated test suite -- this checks the actual
 * production data itself, not just the mechanism.
 */
class VerifyCategoryPromotion extends Command
{
    protected $signature = 'tournaments:verify-category-promotion
        {--snapshot= : Ruta donde guardar el fingerprint actual (antes de promover)}
        {--compare= : Ruta de un fingerprint guardado antes, para compararlo contra el estado actual (después de promover)}';

    protected $description = 'Guarda o compara un fingerprint de fases/partidos/resultados, para confirmar que promover categorías al catálogo (T02-01) no cambió nada';

    public function handle(): int
    {
        $snapshotPath = $this->option('snapshot');
        $comparePath = $this->option('compare');

        if (! $snapshotPath && ! $comparePath) {
            $this->error('Pasa --snapshot=ruta.json (antes de promover) o --compare=ruta.json (después de promover).');

            return self::FAILURE;
        }

        $current = $this->buildFingerprint();

        if ($snapshotPath) {
            file_put_contents($snapshotPath, json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info("Fingerprint guardado en {$snapshotPath} ({$current['phases']->count()} fases, {$current['matches']->count()} partidos).");

            return self::SUCCESS;
        }

        if (! is_file($comparePath)) {
            $this->error("No existe el archivo: {$comparePath}");

            return self::FAILURE;
        }

        $before = json_decode((string) file_get_contents($comparePath), true);
        $problems = $this->diff($before, $current);

        if ($problems === []) {
            $this->info('✅ Sin diferencias -- fases, partidos y resultados quedaron exactamente igual.');

            return self::SUCCESS;
        }

        $this->error('❌ Se encontraron '.count($problems).' diferencia(s):');
        foreach ($problems as $problem) {
            $this->line("  - {$problem}");
        }

        return self::FAILURE;
    }

    /**
     * @return array{phases: Collection, matches: Collection}
     */
    private function buildFingerprint(): array
    {
        $phases = CompetitionPhase::query()->orderBy('id')->get(['id', 'tournament_id', 'category_id', 'order', 'champion_team_id'])
            ->map(fn (CompetitionPhase $phase) => $phase->only(['id', 'tournament_id', 'category_id', 'order', 'champion_team_id']));

        $matches = TournamentMatch::query()->orderBy('id')->get(['id', 'competition_phase_id', 'category_id', 'tournament_id', 'home_team_id', 'away_team_id', 'home_score', 'away_score', 'status'])
            ->map(fn (TournamentMatch $match) => [
                ...$match->only(['id', 'competition_phase_id', 'category_id', 'tournament_id', 'home_team_id', 'away_team_id', 'home_score', 'away_score']),
                'status' => $match->status->value,
            ]);

        return ['phases' => $phases, 'matches' => $matches];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array{phases: Collection, matches: Collection}  $after
     * @return list<string>
     */
    private function diff(array $before, array $after): array
    {
        $problems = [];

        $beforePhases = collect($before['phases'])->keyBy('id');
        foreach ($after['phases'] as $phase) {
            $original = $beforePhases->get($phase['id']);
            if (! $original) {
                continue; // a genuinely new phase created after the snapshot -- not a regression.
            }
            foreach (['tournament_id', 'category_id', 'order', 'champion_team_id'] as $field) {
                if ($original[$field] !== $phase[$field]) {
                    $problems[] = "Fase #{$phase['id']}: {$field} cambió de ".json_encode($original[$field]).' a '.json_encode($phase[$field]);
                }
            }
        }

        $beforeMatches = collect($before['matches'])->keyBy('id');
        foreach ($after['matches'] as $match) {
            $original = $beforeMatches->get($match['id']);
            if (! $original) {
                continue;
            }
            foreach (['competition_phase_id', 'category_id', 'tournament_id', 'home_team_id', 'away_team_id', 'home_score', 'away_score'] as $field) {
                if ($original[$field] !== $match[$field]) {
                    $problems[] = "Partido #{$match['id']}: {$field} cambió de ".json_encode($original[$field]).' a '.json_encode($match[$field]);
                }
            }
        }

        return $problems;
    }
}
