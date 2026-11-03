<?php

namespace App\Http\Controllers;

use App\Models\CompetitionPhase;
use App\Services\TiebreakDrawService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TiebreakDrawController extends Controller
{
    public function store(Request $request, CompetitionPhase $phase, TiebreakDrawService $draws): RedirectResponse
    {
        $this->authorize('create', [CompetitionPhase::class, $phase->category]);

        $data = $request->validate([
            'team_ids' => ['required', 'array', 'min:2'],
            'team_ids.*' => ['integer'],
        ]);

        if (! $draws->draw($phase, $data['team_ids'])) {
            return back()->with('error', __('Ese empate ya no existe o cambió: revisa la tabla antes de sortear.'));
        }

        return back()->with('status', __('Sorteo realizado. El orden quedó guardado.'));
    }

    public function destroy(CompetitionPhase $phase, string $drawGroup, TiebreakDrawService $draws): RedirectResponse
    {
        $this->authorize('create', [CompetitionPhase::class, $phase->category]);

        $draws->annul($phase, $drawGroup);

        return back()->with('status', __('Sorteo anulado. El empate vuelve a quedar pendiente.'));
    }
}
