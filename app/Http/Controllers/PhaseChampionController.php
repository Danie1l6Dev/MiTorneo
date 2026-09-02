<?php

namespace App\Http\Controllers;

use App\Models\CompetitionPhase;
use App\Models\Team;
use App\Services\PhaseEligibilityService;
use App\Services\StandingsService;
use Illuminate\Http\RedirectResponse;

class PhaseChampionController extends Controller
{
    public function store(CompetitionPhase $phase, StandingsService $standingsService, PhaseEligibilityService $eligibilityService): RedirectResponse
    {
        $this->authorize('create', [CompetitionPhase::class, $phase->category]);

        $tables = $standingsService->tablesForPhase($phase);
        $tableCount = collect($tables)->filter(fn (array $table): bool => count($table['rows']) > 0)->count();

        if (! $eligibilityService->canDeclareChampion($phase, $tableCount)) {
            return to_route('phases.show', $phase)->with('error', __(
                'No se puede declarar campeón en esta fase todavía.'
            ));
        }

        $championTeam = $tables[0]['rows'][0]['team'] ?? null;

        if (! $championTeam instanceof Team) {
            return to_route('phases.show', $phase)->with('error', __(
                'No hay ningún equipo en la tabla para declarar campeón.'
            ));
        }

        $phase->champion_team_id = $championTeam->id;
        $phase->save();

        return to_route('phases.show', $phase)->with('status', __(
            ':team es el campeón de :category.',
            ['team' => $championTeam->name, 'category' => $phase->category->name]
        ));
    }

    public function destroy(CompetitionPhase $phase): RedirectResponse
    {
        $this->authorize('update', $phase);

        $phase->champion_team_id = null;
        $phase->save();

        return to_route('phases.show', $phase)->with('status', __(
            'Campeón eliminado. Ya puedes definir clasificados de nuevo.'
        ));
    }
}
