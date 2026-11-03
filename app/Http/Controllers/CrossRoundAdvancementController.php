<?php

namespace App\Http\Controllers;

use App\Enums\CompetitionPhaseType;
use App\Enums\DrawMethod;
use App\Enums\PairingScope;
use App\Enums\ScheduleFormat;
use App\Http\Requests\AdvanceCrossRoundRequest;
use App\Models\CompetitionPhase;
use App\Services\CrossRoundService;
use App\Services\KnockoutBracketService;
use App\Services\PhaseEligibilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Advances from a finished "ronda de cruces" (CompetitionPhase::$cross_round):
 * its winners, the teams that passed directly and the chosen number of best
 * losers are drawn again into a further cross round or a knockout bracket.
 * Advancing from a league lives in PhaseAdvancementController.
 */
class CrossRoundAdvancementController extends Controller
{
    public function create(CompetitionPhase $phase, CrossRoundService $crossRoundService, PhaseEligibilityService $eligibilityService): View|RedirectResponse
    {
        $this->authorize('create', [CompetitionPhase::class, $phase->category]);

        if ($redirect = $this->guard($phase, $crossRoundService, $eligibilityService)) {
            return $redirect;
        }

        $outcome = $crossRoundService->outcome($phase);

        // "Entre grupos" only makes sense when the teams still left come from
        // at least two groups.
        $meta = $crossRoundService->seedingMeta($phase);
        $groupCount = collect($outcome['direct'])
            ->concat($outcome['winners'])
            ->concat(collect($outcome['losers'])->pluck('team'))
            ->map(fn ($team): ?int => $meta[$team->id]['group'] ?? null)
            ->unique()
            ->count();

        return view('pages.phases.advance-cross', compact('phase', 'outcome', 'groupCount'));
    }

    public function store(AdvanceCrossRoundRequest $request, CompetitionPhase $phase, CrossRoundService $crossRoundService, PhaseEligibilityService $eligibilityService, KnockoutBracketService $bracketService): RedirectResponse
    {
        $this->authorize('create', [CompetitionPhase::class, $phase->category]);

        if ($redirect = $this->guard($phase, $crossRoundService, $eligibilityService)) {
            return $redirect;
        }

        $outcome = $crossRoundService->outcome($phase);
        $isCrossRound = $request->validated('type') === AdvanceCrossRoundRequest::CROSS_ROUND;

        $pairing = $crossRoundService->pairPool(
            $outcome['direct']
                ->concat($outcome['winners'])
                ->concat(collect($outcome['losers'])->take((int) $request->validated('best_losers'))->pluck('team')),
            $crossRoundService->seedingMeta($phase),
            DrawMethod::from($request->validated('draw_method')),
            PairingScope::from($request->validated('pairing_scope')),
        );

        // Consecutive teams are each cross: what a bracket's first round and a
        // cross round both read.
        $qualifiers = collect($pairing['pairs'])->flatten(1)->values();

        $newPhase = DB::transaction(function () use ($phase, $request, $qualifiers, $pairing, $isCrossRound, $bracketService): CompetitionPhase {
            $newPhase = new CompetitionPhase;
            $newPhase->tournament_id = $phase->tournament_id;
            $newPhase->category_id = $phase->category_id;
            $newPhase->name = (string) $request->validated('name');
            $newPhase->type = $isCrossRound ? CompetitionPhaseType::Knockout : CompetitionPhaseType::from($request->validated('type'));
            $newPhase->cross_round = $isCrossRound;
            $newPhase->knockout_format = ScheduleFormat::from($isCrossRound ? ScheduleFormat::SingleRound->value : ($request->validated('knockout_format') ?? ScheduleFormat::SingleRound->value));
            $newPhase->plays_third_place = ! $isCrossRound && $request->boolean('plays_third_place');
            $newPhase->final_knockout_format = $isCrossRound || ! $request->filled('final_knockout_format')
                ? null
                : ScheduleFormat::from($request->validated('final_knockout_format'));
            $newPhase->order = $phase->order + 1;
            $newPhase->save();

            $newPhase->teams()->attach($qualifiers->pluck('id'));

            if ($isCrossRound) {
                $bracketService->generateCrossRound($newPhase, $pairing['pairs']);
            } else {
                $bracketService->generateBracket($newPhase, $qualifiers);
            }

            return $newPhase;
        });

        return to_route('phases.show', $newPhase)
            ->with('status', __('Sorteo realizado: :count cruces creados para :name.', ['count' => intdiv($qualifiers->count(), 2), 'name' => $newPhase->name]))
            ->with('drawReveal', true);
    }

    private function guard(CompetitionPhase $phase, CrossRoundService $crossRoundService, PhaseEligibilityService $eligibilityService): ?RedirectResponse
    {
        if (! $phase->cross_round) {
            return to_route('phases.show', $phase)->with('error', __('Solo se puede avanzar de esta forma desde una ronda de cruces.'));
        }

        if (! $phase->allMatchesFinished() || $crossRoundService->outcome($phase)['undecided'] > 0) {
            return to_route('phases.show', $phase)->with('error', __('Todavía hay cruces sin finalizar o sin un ganador definido en esta ronda.'));
        }

        if ($eligibilityService->isAlreadyResolved($phase)) {
            return to_route('phases.show', $phase)->with('error', __(
                'Esta fase ya fue resuelta: ya tiene una fase siguiente creada. Elimínala primero si quieres definir otros clasificados.'
            ));
        }

        return null;
    }
}
