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
 * Exports match results as a PDF, at five levels: one match (full report --
 * rosters, statistics, events, sanctions), one jornada/knockout round, one
 * whole phase, every played match of a category in a tournament split by
 * phase and jornada/round, or -- exportTournament() -- every category of the
 * tournament together, split by the jornada(s) chosen (mirrors
 * MatchProgrammingPdfController's "Exportar programación", for results
 * instead of pending matches). Same per-user letterhead switch as the
 * standings export (generic MiTorneo vs. Faudis' LIFUTGUA), see
 * PdfLetterheadService.
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
                'heading' => __('Fase: :name', ['name' => $phase->name]),
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

    /**
     * Every category's PLAYED matches of $tournament, jornada by jornada, for
     * the chosen ?rounds= (5,6, or "all" for every jornada with a played
     * match) -- same URL contract as MatchProgrammingPdfController::export(),
     * so x-ui.results-export can reuse its exact jornada-picker JS.
     */
    public function exportTournament(Request $request, Tournament $tournament): StreamedResponse|Response
    {
        $this->authorize('view', $tournament);

        $validated = $request->validate([
            'rounds' => ['required_without:from', 'nullable', 'string', 'regex:/^(all|\d+(,\d+)*)$/'],
            'from' => ['required_without:rounds', 'nullable', 'date_format:Y-m-d'],
            'to' => ['required_with:from', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'category' => ['nullable', 'integer'],
        ]);

        $byDays = isset($validated['from']);

        $category = null;

        if (isset($validated['category'])) {
            // Only a category actually played in THIS tournament.
            abort_unless($tournament->competitionPhases()->where('category_id', $validated['category'])->exists(), 404);
            $category = Category::query()->findOrFail($validated['category']);
        }

        $roundNumbers = $byDays || $validated['rounds'] === 'all'
            ? null
            : collect(explode(',', $validated['rounds']))->map(fn (string $round): int => (int) $round)->unique()->sort()->values()->all();

        $phases = $byDays
            ? $this->report->tournamentDaySections($tournament, $validated['from'], $validated['to'], $category)
            : $this->report->tournamentRoundSections($tournament, $roundNumbers);

        abort_if($phases === [], 404);

        $pdf = Pdf::loadView('pdf.match-results', [
            'tournament' => $tournament,
            'meta' => $category ? [__('Categoría') => $category->name] : [],
            'phases' => $phases,
            ...$this->letterhead->forUser(auth()->user()),
        ])->setPaper('letter');

        $roundsSlug = match (true) {
            $byDays => 'del-'.$validated['from'].'-al-'.$validated['to'],
            $roundNumbers === null => 'todas-las-jornadas',
            default => 'jornada-'.implode('-', $roundNumbers),
        };

        return $pdf->download('resultados-'.str($tournament->name.'-'.($category ? $category->name.'-' : '').$roundsSlug)->slug().'.pdf');
    }
}
