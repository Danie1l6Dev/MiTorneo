<?php

namespace App\Http\Controllers;

use App\Enums\MatchStatus;
use App\Http\Requests\MatchResultRequest;
use App\Models\TournamentMatch;
use App\Services\KnockoutBracketService;
use Illuminate\Http\RedirectResponse;

class MatchResultController extends Controller
{
    public function update(MatchResultRequest $request, TournamentMatch $match, KnockoutBracketService $bracketService): RedirectResponse
    {
        $this->authorize('update', $match);

        $match->home_score = $request->validated('home_score');
        $match->away_score = $request->validated('away_score');
        $match->status = MatchStatus::Finished;
        $match->save();

        $bracketService->resolveWinner($match);

        return to_route('matches.edit', $match)->with('status', __('Resultado registrado correctamente.'));
    }
}
