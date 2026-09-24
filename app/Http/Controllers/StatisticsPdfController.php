<?php

namespace App\Http\Controllers;

use App\Enums\MatchEventType;
use App\Models\Category;
use App\Models\Tournament;
use App\Services\CompetitionStatisticsService;
use App\Services\PdfLetterheadService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Player statistics tables (goleadores, asistidores, amarillas, rojas) of a
 * category in a tournament as a PDF, one column per phase plus the total --
 * ?type= picks one table, or "all" for the four of them, one per page.
 * Same per-user letterhead switch as every other export, see
 * PdfLetterheadService.
 */
class StatisticsPdfController extends Controller
{
    public function export(Request $request, Tournament $tournament, Category $category, CompetitionStatisticsService $statistics, PdfLetterheadService $letterhead): StreamedResponse|Response
    {
        $this->authorize('view', $tournament);

        $validated = $request->validate([
            'type' => ['required', Rule::in([...array_column(MatchEventType::cases(), 'value'), 'all'])],
        ]);

        abort_unless($tournament->competitionPhases()->where('category_id', $category->id)->exists(), 404);

        $types = $validated['type'] === 'all' ? MatchEventType::cases() : [MatchEventType::from($validated['type'])];

        $tables = array_map(fn (MatchEventType $type): array => [
            'type' => $type,
            ...$statistics->phaseBreakdown($tournament, $category, $type),
        ], $types);

        $pdf = Pdf::loadView('pdf.statistics', [
            'tournament' => $tournament,
            'category' => $category,
            'tables' => $tables,
            ...$letterhead->forUser($request->user()),
        ])->setPaper('letter');

        $typeSlug = $validated['type'] === 'all' ? 'estadisticas' : str($types[0]->leaderboardTitle())->slug();

        return $pdf->download($typeSlug.'-'.str($tournament->name.'-'.$category->name)->slug().'.pdf');
    }
}
