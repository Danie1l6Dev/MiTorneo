<?php

namespace App\Console\Commands;

use App\Services\PlayerHistoryBackfillService;
use Illuminate\Console\Command;

class CheckPlayerHistory extends Command
{
    protected $signature = 'players:check-history {--owner= : Limitar a los jugadores de un organizador (id de usuario)}';

    protected $description = 'Compara el historial de planteles con los planteles reales de cada jugador y avisa de los desajustes (solo lectura)';

    public function handle(PlayerHistoryBackfillService $backfill): int
    {
        $problems = $backfill->drift($this->option('owner') !== null ? (int) $this->option('owner') : null);

        if ($problems === []) {
            $this->info('El historial coincide con los planteles de todos los jugadores.');

            return self::SUCCESS;
        }

        $this->error(count($problems).' desajuste(s):');
        $this->table(['Jugador', 'Problema'], array_map(fn (array $problem): array => [$problem['player'], $problem['problem']], array_slice($problems, 0, 60)));

        return self::FAILURE;
    }
}
