<?php

namespace App\Console\Commands;

use App\Services\PlayerHistoryBackfillService;
use Illuminate\Console\Command;

class BackfillPlayerHistory extends Command
{
    protected $signature = 'players:backfill-history {--dry-run : Solo mostrar qué líneas de historial se crearían, sin escribir nada} {--owner= : Limitar a los jugadores de un organizador (id de usuario)}';

    protected $description = 'Crea el historial de planteles que los jugadores tenían antes de que se registrara (solo inserta, marcado como estimado)';

    public function handle(PlayerHistoryBackfillService $backfill): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $ownerId = $this->option('owner') !== null ? (int) $this->option('owner') : null;

        $report = $backfill->run($dryRun, $ownerId);

        $this->line($dryRun ? '== SIMULACIÓN: no se escribió nada ==' : '== Historial creado ==');
        $this->line("Jugadores revisados: {$report['players']}");
        $this->line("Líneas de planteles actuales: {$report['current']}");
        $this->line("Líneas de planteles pasados (deducidas de eventos y sanciones): {$report['past']}");

        if ($report['rows'] !== []) {
            $this->table(['Jugador', 'Plantel', 'Tipo', 'Desde', 'Hasta'], array_map(fn (array $row): array => [$row['player'], $row['team'], $row['kind'], $row['from'], $row['to'] ?? '—'], array_slice($report['rows'], 0, 40)));

            if (count($report['rows']) > 40) {
                $this->line('… y '.(count($report['rows']) - 40).' más.');
            }
        }

        if ($report['current'] + $report['past'] === 0) {
            $this->info('Nada que crear: todos los jugadores ya tienen su historial.');
        }

        return self::SUCCESS;
    }
}
