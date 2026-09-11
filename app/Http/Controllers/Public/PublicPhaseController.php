<?php

namespace App\Http\Controllers\Public;

use App\Enums\CompetitionPhaseType;
use App\Http\Controllers\Controller;
use App\Models\CompetitionPhase;
use App\Models\Tournament;
use App\Services\CompetitionStatisticsService;
use App\Services\PhaseBoardService;
use App\Services\StandingsService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The public portal's page for one phase (standings, calendar, bracket,
 * player statistics) -- read-only, reachable without logging in (see
 * routes/public.php). Shares its view-model computation with the admin
 * phase page through PhaseBoardService/StandingsService/
 * CompetitionStatisticsService instead of re-deriving it, so both pages
 * always agree and never run duplicate queries for the same data.
 *
 * Deliberately omits everything admin-only from CompetitionPhaseController::show():
 * schedule generation/deletion, phase advancement, champion declaration, and
 * the live draw-reveal (which is only ever flashed right after an admin
 * action, so it never has anything to show here).
 */
class PublicPhaseController extends Controller
{
    public function show(
        Request $request,
        Tournament $tournament,
        CompetitionPhase $phase,
        StandingsService $standingsService,
        CompetitionStatisticsService $statisticsService,
        PhaseBoardService $boardService,
    ): View {
        // $phase is bound straight from its own id, so a mismatched pair in
        // the URL must 404 instead of silently showing a phase from a
        // different tournament.
        if ($phase->tournament_id !== $tournament->id) {
            abort(404);
        }

        $category = $phase->category;
        $category->load('groups');

        $schedules = $boardService->scheduleViews($phase);

        $bracketRounds = $phase->type !== CompetitionPhaseType::League
            ? $boardService->bracketRounds($phase)
            : [];

        $bracketColumns = $boardService->bracketColumns($bracketRounds);

        $bracketSize = $boardService->bracketSizeTokens(count($bracketRounds));

        $standings = $phase->type === CompetitionPhaseType::League
            ? $standingsService->tablesForPhase($phase)
            : [];

        $champion = $phase->type === CompetitionPhaseType::League
            ? $phase->champion
            : $boardService->championFromBracket($bracketRounds);

        $statistics = $phase->type === CompetitionPhaseType::League
            ? $boardService->statisticsView($request, $category, $statisticsService)
            : null;

        return view('pages.public.phases.show', compact(
            'tournament', 'phase', 'category', 'schedules', 'bracketRounds', 'bracketColumns', 'bracketSize',
            'champion', 'standings', 'statistics'
        ));
    }
}
