<?php

namespace App\Http\Controllers;

use App\Enums\CompetitionPhaseType;
use App\Http\Requests\TournamentMatchRequest;
use App\Models\CompetitionPhase;
use App\Models\TournamentMatch;
use App\Services\KnockoutBracketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class TournamentMatchController extends Controller
{
    public function create(CompetitionPhase $phase): View
    {
        $this->authorize('create', [TournamentMatch::class, $phase]);

        $groups = $phase->category->groups;
        $teams = $phase->category->teams;

        return view('pages.matches.create', compact('phase', 'groups', 'teams'));
    }

    public function store(TournamentMatchRequest $request, CompetitionPhase $phase): RedirectResponse
    {
        $this->authorize('create', [TournamentMatch::class, $phase]);

        $match = $phase->matches()->make($request->validated());
        $match->tournament_id = $phase->tournament_id;
        $match->category_id = $phase->category_id;
        $match->save();

        return to_route('phases.show', $phase);
    }

    public function edit(TournamentMatch $match): View
    {
        $this->authorize('update', $match);

        $groups = $match->competitionPhase->category->groups;
        $teams = $match->competitionPhase->category->teams;

        return view('pages.matches.edit', compact('match', 'groups', 'teams'));
    }

    public function update(TournamentMatchRequest $request, TournamentMatch $match, KnockoutBracketService $bracketService): RedirectResponse
    {
        $this->authorize('update', $match);

        $match->update($request->validated());

        if ($match->home_score !== null && $match->away_score !== null) {
            $bracketService->resolveWinner($match);
        }

        $isKnockoutMatch = $match->competitionPhase->type !== CompetitionPhaseType::League;

        return redirect(route('phases.show', $match->competitionPhase).($isKnockoutMatch ? '#cuadro' : '#calendario'))
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
