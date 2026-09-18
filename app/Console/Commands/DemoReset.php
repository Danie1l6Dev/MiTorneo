<?php

namespace App\Console\Commands;

use App\Services\DemoResetService;
use Illuminate\Console\Command;

class DemoReset extends Command
{
    protected $signature = 'demo:reset';

    protected $description = 'Restablece los datos del usuario demo público (solo ese usuario, nunca el resto de la base de datos)';

    public function handle(DemoResetService $service): int
    {
        // Opt-in per environment: with the flag off (the default) this does
        // nothing, so it can never run by accident against a real database.
        if (! config('demo.enabled')) {
            $this->error('DEMO_ENABLED no está activo: no se restablece nada.');

            return self::FAILURE;
        }

        $service->reset();

        $this->info('Datos del usuario demo restablecidos.');

        return self::SUCCESS;
    }
}
