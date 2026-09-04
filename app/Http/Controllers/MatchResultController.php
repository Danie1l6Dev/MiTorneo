<?php

namespace App\Http\Controllers;

use App\Enums\CompetitionPhaseType;
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

        $isKnockoutMatch = $match->competitionPhase->type !== CompetitionPhaseType::League;

        $match->home_extra_time_score = $isKnockoutMatch ? $request->validated('home_extra_time_score') : null;
        $match->away_extra_time_score = $isKnockoutMatch ? $request->validated('away_extra_time_score') : null;

        // Penalties only make sense once the aggregate (regular + extra time)
        // score is level; if it isn't, that score already has a winner, so
        // any penalty result submitted alongside it is stale and dropped
        // rather than trusted, regardless of what the client sent.
        $aggregateIsLevel = $match->home_score + ($match->home_extra_time_score ?? 0)
            === $match->away_score + ($match->away_extra_time_score ?? 0);

        $match->home_penalty_score = $isKnockoutMatch && $aggregateIsLevel ? $request->validated('home_penalty_score') : null;
        $match->away_penalty_score = $isKnockoutMatch && $aggregateIsLevel ? $request->validated('away_penalty_score') : null;

        $match->status = MatchStatus::Finished;
        $match->save();

        $bracketService->resolveWinner($match);

        // Land back on the same group's calendar tab, not just the calendar
        // section in general -- otherwise registering results one after
        // another for group B keeps bouncing the view back to group A.
        $hash = match (true) {
            $isKnockoutMatch => '#cuadro',
            $match->group_id !== null => "#calendario-grupo-{$match->group_id}",
            default => '#calendario',
        };

        return redirect(route('phases.show', $match->competitionPhase).$hash)
            ->with('status', __('Resultado registrado correctamente.'));
    }
}
