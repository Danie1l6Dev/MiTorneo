<?php

namespace App\Http\Controllers;

use App\Enums\CompetitionPhaseType;
use App\Models\Category;
use App\Models\CompetitionPhase;
use App\Models\Tournament;
use App\Services\PdfLetterheadService;
use App\Services\StandingsService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exports standings tables (a single phase, or every league phase of a whole
 * tournament) as a PDF. Available to every organizer: by default it prints
 * the generic MiTorneo letterhead; Faudis' account (see
 * User::usesMunicipalLetterhead()) prints the LIFUTGUA letterhead his league
 * already uses on its official programming sheets -- see PdfLetterheadService.
 */
class MunicipalStandingsPdfController extends Controller
{
    public function export(CompetitionPhase $phase, StandingsService $standingsService, PdfLetterheadService $letterhead): StreamedResponse|Response
    {
        $this->authorize('view', $phase);

        abort_unless($phase->type === CompetitionPhaseType::League, 404);

        $category = $phase->category;
        $tournament = $phase->tournament;
        $tables = $standingsService->tablesForPhase($phase);

        $pdf = Pdf::loadView('pdf.standings-municipal', [
            'phase' => $phase,
            'category' => $category,
            'tournament' => $tournament,
            'tables' => $tables,
            ...$letterhead->forUser(auth()->user()),
        ])->setPaper('letter');

        $fileName = 'tabla-posiciones-'.str($category->name.'-'.$phase->name)->slug().'.pdf';

        return $pdf->download($fileName);
    }

    /**
     * Every league-type phase of every category in $tournament, one PDF --
     * e.g. for a coordinator handing out the whole tournament's standings at
     * once instead of one export per category. A category with no
     * league-type phase yet (still knockout-only, or not started) simply
     * doesn't get a section.
     */
    public function exportTournament(Tournament $tournament, StandingsService $standingsService, PdfLetterheadService $letterhead): StreamedResponse|Response
    {
        $this->authorize('view', $tournament);

        $leaguePhases = $tournament->competitionPhases()
            ->where('type', CompetitionPhaseType::League)
            ->orderBy('order')
            ->get();

        $categories = Category::query()
            ->whereIn('id', $leaguePhases->pluck('category_id')->unique())
            ->orderedByAge()
            ->get();

        abort_if($categories->isEmpty(), 404);

        $sections = $categories->map(fn (Category $category): array => [
            'category' => $category,
            'phases' => $leaguePhases
                ->where('category_id', $category->id)
                ->map(fn (CompetitionPhase $phase): array => [
                    'phase' => $phase,
                    'tables' => $standingsService->tablesForPhase($phase),
                ])
                ->values(),
        ]);

        $pdf = Pdf::loadView('pdf.standings-municipal-tournament', [
            'tournament' => $tournament,
            'sections' => $sections,
            ...$letterhead->forUser(auth()->user()),
        ])->setPaper('letter');

        $fileName = 'tabla-posiciones-'.str($tournament->name)->slug().'.pdf';

        return $pdf->download($fileName);
    }
}
