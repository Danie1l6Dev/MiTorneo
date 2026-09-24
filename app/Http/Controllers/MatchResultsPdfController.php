<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\CompetitionPhase;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Services\MatchResultsReportService;
use App\Services\PdfLetterheadService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exports match results as a PDF, at four levels: one match (full report --
 * rosters, statistics, events, sanctions), one jornada/knockout round, one
 * whole phase, or every played match of a category in a tournament split by
 * phase and jornada/round. Same per-user letterhead switch as the standings
 * export (generic MiTorneo vs. Faudis' LIFUTGUA), see PdfLetterheadService.
 */
class MatchResultsPdfController extends Controller
{
    public function __construct(
        private MatchResultsReportService $report,
        private PdfLetterheadService $letterhead,
    ) {}

    public function exportMatch(TournamentMatch $match): StreamedResponse|Response
    {
        $this->authorize('view', $match);

        $pdf = Pdf::loadView('pdf.match-report', [
            ...$this->report->matchReport($match),
            ...$this->letterhead->forUser(auth()->user()),
        ])->setPaper('letter');

        $teams = collect([$match->homeTeam?->name, $match->awayTeam?->name])->filter()->implode(' vs ');

        return $pdf->download('resultado-partido-'.str($teams ?: (string) $match->id)->slug().'.pdf');
    }

    /**
     * A whole phase, or -- with ?round= -- just one of its jornadas (league)
     * or rounds (knockout, e.g. octavos de final).
     */
    public function exportPhase(Request $request, CompetitionPhase $phase): StreamedResponse|Response
    {
        $this->authorize('view', $phase);

        $roundNumber = $request->filled('round') ? $request->integer('round') : null;
        $sections = $this->report->phaseSections($phase, $roundNumber);

        abort_if($roundNumber !== null && $sections->isEmpty(), 404);

        $category = $phase->category;
        // The jornada/round itself is already each section's own heading in
        // the body, so it isn't repeated up here.
        $meta = [
            __('Categoría') => $category->name,
            __('Fase') => $phase->name,
        ];

        $pdf = Pdf::loadView('pdf.match-results', [
            'tournament' => $phase->tournament,
            'meta' => $meta,
            'phases' => [['heading' => null, 'sections' => $sections]],
            ...$this->letterhead->forUser(auth()->user()),
        ])->setPaper('letter');

        $fileName = 'resultados-'.str(collect([$category->name, $phase->name, $roundNumber !== null ? $sections->first()['title'] : null])->filter()->implode('-'))->slug().'.pdf';

        return $pdf->download($fileName);
    }

    /**
     * Every PLAYED match of $category in $tournament, phase by phase (in
     * the order the category's phases were chained) and jornada/round by
     * jornada/round.
     */
    public function exportCategory(Tournament $tournament, Category $category): StreamedResponse|Response
    {
        $this->authorize('view', $tournament);

        $phases = $tournament->competitionPhases()
            ->where('category_id', $category->id)
            ->orderBy('order')
            ->orderBy('id')
            ->get();

        abort_if($phases->isEmpty(), 404);

        $phaseSections = $phases
            ->map(fn (CompetitionPhase $phase): array => [
                'heading' => $phase->name,
                'sections' => $this->report->phaseSections($phase, onlyPlayed: true),
            ])
            ->filter(fn (array $entry): bool => $entry['sections']->isNotEmpty())
            ->values()
            ->all();

        $pdf = Pdf::loadView('pdf.match-results', [
            'tournament' => $tournament,
            'meta' => [__('Categoría') => $category->name],
            'phases' => $phaseSections,
            ...$this->letterhead->forUser(auth()->user()),
        ])->setPaper('letter');

        return $pdf->download('resultados-'.str($tournament->name.'-'.$category->name)->slug().'.pdf');
    }
}
