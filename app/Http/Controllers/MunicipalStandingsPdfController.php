<?php

namespace App\Http\Controllers;

use App\Enums\CompetitionPhaseType;
use App\Models\Category;
use App\Models\CompetitionPhase;
use App\Models\Tournament;
use App\Services\StandingsService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exports standings tables (a single phase, or every league phase of a whole
 * tournament) as a PDF. Available to every organizer: by default it prints
 * the generic MiTorneo letterhead; Faudis' account (see
 * User::usesMunicipalLetterhead()) prints the LIFUTGUA letterhead his league
 * already uses on its official programming sheets. That federation
 * branding/NIT would be meaningless (or actively wrong) on anyone else's
 * export, hence the per-user switch.
 */
class MunicipalStandingsPdfController extends Controller
{
    public function export(CompetitionPhase $phase, StandingsService $standingsService): StreamedResponse|Response
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
            ...$this->letterhead(),
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
    public function exportTournament(Tournament $tournament, StandingsService $standingsService): StreamedResponse|Response
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
            ...$this->letterhead(),
        ])->setPaper('letter');

        $fileName = 'tabla-posiciones-'.str($tournament->name)->slug().'.pdf';

        return $pdf->download($fileName);
    }

    /**
     * @return array<string, string>
     */
    private function letterhead(): array
    {
        if (! auth()->user()->usesMunicipalLetterhead()) {
            return [
                'letterhead' => 'default',
                'appLogo' => $this->imageAsDataUri('mitorneo-logo.svg'),
            ];
        }

        return [
            'letterhead' => 'municipal',
            'lifutguaLogo' => $this->imageAsDataUri('lifutgua.jpg'),
            'difutbolLogo' => $this->imageAsDataUri('difutbol.jpg'),
            'wordmark' => $this->imageAsDataUri('lifutgua-wordmark.png'),
            'signature' => $this->imageAsDataUri('coordinador-firma.jpg'),
        ];
    }

    private function imageAsDataUri(string $fileName): string
    {
        $path = resource_path("images/standings-pdf/{$fileName}");
        $mimeType = match (pathinfo($fileName, PATHINFO_EXTENSION)) {
            'png' => 'image/png',
            'svg' => 'image/svg+xml',
            default => 'image/jpeg',
        };

        return "data:{$mimeType};base64,".base64_encode(File::get($path));
    }
}
