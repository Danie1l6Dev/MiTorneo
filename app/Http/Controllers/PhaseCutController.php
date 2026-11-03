<?php

namespace App\Http\Controllers;

use App\Models\CompetitionPhase;
use App\Services\PhaseCutService;
use Illuminate\Http\RedirectResponse;

class PhaseCutController extends Controller
{
    public function store(CompetitionPhase $phase, PhaseCutService $cutService): RedirectResponse
    {
        $this->authorize('create', [CompetitionPhase::class, $phase->category]);

        if (! $cutService->canCut($phase)) {
            return to_route('phases.show', $phase)->with('error', __('Esta fase no tiene partidos pendientes que recortar, o ya se avanzó a partir de ella.'));
        }

        $count = $cutService->cut($phase);

        return to_route('phases.show', $phase)->with('status', trans_choice(
            'Se recortó la liga: :count partido pendiente quedó cancelado. Ya puedes pasar a la siguiente fase.|Se recortó la liga: :count partidos pendientes quedaron cancelados. Ya puedes pasar a la siguiente fase.',
            $count,
            ['count' => $count]
        ));
    }

    public function destroy(CompetitionPhase $phase, PhaseCutService $cutService): RedirectResponse
    {
        $this->authorize('create', [CompetitionPhase::class, $phase->category]);

        if (! $cutService->canRestore($phase)) {
            return to_route('phases.show', $phase)->with('error', __('No se puede deshacer el recorte: no hay partidos recortados, o ya se avanzó a partir de esta fase.'));
        }

        $count = $cutService->restore($phase);

        return to_route('phases.show', $phase)->with('status', trans_choice(
            'Se deshizo el recorte: :count partido volvió a quedar programado.|Se deshizo el recorte: :count partidos volvieron a quedar programados.',
            $count,
            ['count' => $count]
        ));
    }
}
