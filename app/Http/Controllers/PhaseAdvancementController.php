<?php

namespace App\Http\Controllers;

use App\Enums\CompetitionPhaseType;
use App\Http\Requests\AdvancePhaseRequest;
use App\Models\CompetitionPhase;
use App\Services\KnockoutBracketService;
use App\Services\StandingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PhaseAdvancementController extends Controller
{
    public function create(CompetitionPhase $phase, StandingsService $standingsService): View|RedirectResponse
    {
        $this->authorize('create', [CompetitionPhase::class, $phase->category]);

        if ($redirect = $this->guard($phase)) {
            return $redirect;
        }

        $tables = $standingsService->tablesForPhase($phase);

        $maxPerTable = collect($tables)
            ->map(fn (array $table): int => count($table['rows']))
            ->filter(fn (int $count): bool => $count > 0)
            ->min() ?? 0;

        return view('pages.phases.advance', compact('phase', 'tables', 'maxPerTable'));
    }

    public function store(AdvancePhaseRequest $request, CompetitionPhase $phase, StandingsService $standingsService, KnockoutBracketService $bracketService): RedirectResponse
    {
        $this->authorize('create', [CompetitionPhase::class, $phase->category]);

        if ($redirect = $this->guard($phase)) {
            return $redirect;
        }

        $type = CompetitionPhaseType::from($request->validated('type'));
        $isLeague = $type === CompetitionPhaseType::League;

        $perTable = match ($type) {
            CompetitionPhaseType::Semifinal => 2,
            CompetitionPhaseType::Final => 1,
            default => (int) $request->validated('qualifiers_per_table'),
        };

        $tables = $standingsService->tablesForPhase($phase);
        $qualifiers = $standingsService->topQualifiers($tables, $perTable);

        $newPhase = DB::transaction(function () use ($phase, $request, $type, $qualifiers, $isLeague, $bracketService): CompetitionPhase {
            $newPhase = new CompetitionPhase;
            $newPhase->tournament_id = $phase->tournament_id;
            $newPhase->category_id = $phase->category_id;
            $newPhase->name = (string) $request->validated('name');
            $newPhase->type = $type;
            $newPhase->order = $phase->order + 1;
            $newPhase->save();

            $newPhase->teams()->attach($qualifiers->pluck('id'));

            if (! $isLeague) {
                $bracketService->generateBracket($newPhase, $qualifiers);
            }

            return $newPhase;
        });

        $status = $isLeague
            ? __(':count equipos clasificados a :name. Genera el calendario de la nueva fase cuando quieras.', ['count' => $qualifiers->count(), 'name' => $newPhase->name])
            : __('Sorteo realizado: :count cruces creados para :name.', ['count' => intdiv($qualifiers->count(), 2), 'name' => $newPhase->name]);

        $redirect = to_route('phases.show', $newPhase)->with('status', $status);

        // Flag a single-use flash so the destination page can play the live
        // draw reveal animation once, using the matches this request just
        // created, instead of showing them instantly.
        if (! $isLeague) {
            $redirect->with('drawReveal', true);
        }

        return $redirect;
    }

    private function guard(CompetitionPhase $phase): ?RedirectResponse
    {
        if ($phase->type !== CompetitionPhaseType::League) {
            return to_route('phases.show', $phase)->with('error', __('Solo se puede avanzar de fase desde una fase de liga.'));
        }

        if (! $phase->allMatchesFinished()) {
            return to_route('phases.show', $phase)->with('error', __('Todavía hay partidos sin finalizar en esta fase.'));
        }

        return null;
    }
}
