<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Club;
use App\Models\Coach;
use App\Models\CompetitionPhase;
use App\Models\Group;
use App\Models\Player;
use App\Models\Referee;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\Venue;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * One-time backfill for the App\Models\Concerns\NormalizesToUppercase trait:
 * that trait only uppercases a model's name field(s) going forward, on each
 * save -- this fixes every row written before the trait existed. Safe to
 * run more than once: an already-uppercase row has nothing to change, so
 * save() finds it clean and skips the UPDATE.
 */
class NormalizeNamesToUppercase extends Command
{
    protected $signature = 'app:normalize-names-to-uppercase {--dry-run : Solo contar cuántos registros cambiarían, sin escribir nada}';

    protected $description = 'Pasa a mayúsculas los campos de nombre ya guardados (clubes, equipos, categorías, jugadores, DTs, árbitros, canchas, torneos, grupos, fases)';

    /**
     * @var array<class-string<Model>, string>
     */
    private const MODELS = [
        Club::class => 'Clubes',
        Team::class => 'Equipos',
        Category::class => 'Categorías',
        Player::class => 'Jugadores',
        Coach::class => 'DTs',
        Referee::class => 'Árbitros',
        Venue::class => 'Canchas',
        Tournament::class => 'Torneos',
        Group::class => 'Grupos',
        CompetitionPhase::class => 'Fases',
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $rows = [];

        foreach (self::MODELS as $modelClass => $label) {
            /** @var Model $probe */
            $probe = new $modelClass;
            $attributes = $probe->uppercaseAttributeNames();

            $needsChange = 0;
            $changed = 0;

            $modelClass::query()->chunkById(200, function ($models) use ($attributes, $dryRun, &$needsChange, &$changed): void {
                foreach ($models as $model) {
                    $willChange = false;

                    foreach ($attributes as $attribute) {
                        $value = $model->{$attribute};
                        if ($value !== null && $value !== mb_strtoupper($value)) {
                            $willChange = true;
                        }
                    }

                    if (! $willChange) {
                        continue;
                    }

                    $needsChange++;

                    if (! $dryRun) {
                        // The trait's saving() listener does the actual
                        // uppercasing -- this just needs to trigger a save.
                        $model->save();
                        $changed++;
                    }
                }
            });

            $rows[] = [$label, implode(', ', $attributes), $needsChange, $dryRun ? '—' : $changed];
        }

        $this->table(['Modelo', 'Campo(s)', 'A cambiar', $dryRun ? 'Sin escribir (dry-run)' : 'Actualizados'], $rows);

        return self::SUCCESS;
    }
}
