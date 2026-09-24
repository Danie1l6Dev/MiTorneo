<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Tournament;
use App\Services\MatchProgrammingReportService;
use App\Services\PdfLetterheadService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The official "programación" PDF: the pending matches of the chosen fechas
 * (?rounds=5,6, or ?rounds=all for every pending one) across the whole
 * tournament, or -- with ?category= -- just one category. Same per-user
 * letterhead switch as every other export, see PdfLetterheadService.
 */
class MatchProgrammingPdfController extends Controller
{
    public function export(Request $request, Tournament $tournament, MatchProgrammingReportService $programming, PdfLetterheadService $letterhead): StreamedResponse|Response
    {
        $this->authorize('view', $tournament);

        $validated = $request->validate([
            'rounds' => ['required', 'string', 'regex:/^(all|\d+(,\d+)*)$/'],
            'category' => ['nullable', 'integer'],
        ]);

        $roundNumbers = $validated['rounds'] === 'all'
            ? null
            : collect(explode(',', $validated['rounds']))->map(fn (string $round): int => (int) $round)->unique()->sort()->values()->all();

        $category = null;

        if (isset($validated['category'])) {
            // Only a category actually played in THIS tournament -- never a
            // bare Category::find(), which would accept anyone's id.
            abort_unless($tournament->competitionPhases()->where('category_id', $validated['category'])->exists(), 404);
            $category = Category::query()->findOrFail($validated['category']);
        }

        $sections = $programming->sections($tournament, $roundNumbers, $category);

        abort_if($sections === [], 404);

        $pdf = Pdf::loadView('pdf.match-programming', [
            'tournament' => $tournament,
            'category' => $category,
            'sections' => $sections,
            ...$letterhead->forUser($request->user()),
        ])->setPaper('letter');

        $roundsSlug = $roundNumbers === null ? 'todas-las-fechas' : 'fecha-'.implode('-', $roundNumbers);
        $fileName = 'programacion-'.str(collect([$tournament->name, $category?->name, $roundsSlug])->filter()->implode('-'))->slug().'.pdf';

        return $pdf->download($fileName);
    }
}
