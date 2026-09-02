<?php

namespace App\Http\Controllers;

use App\Enums\CompetitionPhaseType;
use App\Http\Requests\TournamentMatchRequest;
use App\Models\TournamentMatch;
use App\Services\KnockoutBracketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class TournamentMatchController extends Controller
{
    public function edit(TournamentMatch $match): View
    {
        $this->authorize('update', $match);

        return view('pages.matches.edit', compact('match'));
    }

    public function update(TournamentMatchRequest $request, TournamentMatch $match, KnockoutBracketService $bracketService): RedirectResponse
    {
        $this->authorize('update', $match);

        $match->update($request->validated());

        if ($match->home_score !== null && $match->away_score !== null) {
            $bracketService->resolveWinner($match);
        }

        $isKnockoutMatch = $match->competitionPhase->type !== CompetitionPhaseType::League;

        return redirect(route('phases.show', $match->competitionPhase).($isKnockoutMatch ? '#cuadro' : ''))
            ->with('status', __('Cambios guardados correctamente.'));
    }

    public function destroy(TournamentMatch $match): RedirectResponse
    {
        $this->authorize('delete', $match);

        $phase = $match->competitionPhase;

        $match->delete();

        return to_route('phases.show', $phase);
    }
}
