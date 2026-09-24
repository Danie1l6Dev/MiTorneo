<?php

namespace App\Http\Controllers;

use App\Enums\MatchStatus;
use App\Http\Requests\MatchResultRequest;
use App\Models\TournamentMatch;
use App\Services\KnockoutBracketService;
use App\Services\MatchEventBatchService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class MatchResultController extends Controller
{
    public function update(MatchResultRequest $request, TournamentMatch $match, KnockoutBracketService $bracketService, MatchEventBatchService $eventBatch): RedirectResponse
    {
        $this->authorize('update', $match);

        if ($match->isLockedByExpulsion()) {
            return to_route('matches.edit', $match)->with('error', $match->expulsionLockMessage());
        }

        $match->home_score = $request->validated('home_score');
        $match->away_score = $request->validated('away_score');

        $isDecisiveLeg = $match->isDecisiveKnockoutLeg();

        $match->home_extra_time_score = $isDecisiveLeg ? $request->validated('home_extra_time_score') : null;
        $match->away_extra_time_score = $isDecisiveLeg ? $request->validated('away_extra_time_score') : null;

        // Penalties only make sense once the aggregate (regular + extra
        // time, and -- for the decisive leg of a two-legged cross -- the
        // first leg's score too) is level; if it isn't, that score already
        // has a winner, so any penalty result submitted alongside it is
        // stale and dropped rather than trusted, regardless of what the
        // client sent.
        $aggregate = $isDecisiveLeg ? $match->regularTimeAggregate() : null;
        $aggregateIsLevel = $aggregate !== null
            && $aggregate['home'] + ($match->home_extra_time_score ?? 0) === $aggregate['away'] + ($match->away_extra_time_score ?? 0);

        $match->home_penalty_score = $aggregateIsLevel ? $request->validated('home_penalty_score') : null;
        $match->away_penalty_score = $aggregateIsLevel ? $request->validated('away_penalty_score') : null;

        $match->status = MatchStatus::Finished;

        // Any events still queued on the page ("Por guardar") are saved in
        // the same transaction -- they already passed the exact same rules
        // as the "Guardar eventos" button (see MatchResultRequest), so
        // either both the score and the events land, or neither does.
        $savedEvents = DB::transaction(function () use ($match, $request, $eventBatch, $bracketService): int {
            $match->save();

            $bracketService->resolveWinner($match);

            return $eventBatch->store($match, $request->validated('events') ?? []);
        });

        // Stays on the match itself rather than bouncing back to the
        // phase's calendar/bracket -- an organizer registering a result
        // usually wants to immediately follow up with events (goals,
        // cards) for the very match they just scored, not navigate away
        // from it. "Volver al calendario" (see the match edit page) is the
        // explicit way back when they're actually done with it.
        return to_route('matches.edit', $match)->with('status', $savedEvents > 0
            ? trans_choice(
                'Resultado registrado correctamente, junto con :count evento.|Resultado registrado correctamente, junto con :count eventos.',
                $savedEvents,
                ['count' => $savedEvents]
            )
            : __('Resultado registrado correctamente.'));
    }
}
