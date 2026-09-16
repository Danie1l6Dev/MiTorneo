<?php

namespace App\Http\Controllers;

use App\Enums\CompetitionPhaseType;
use App\Models\CompetitionPhase;
use App\Services\StandingsService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exports a phase's standings table as a PDF using the letterhead LIFUTGUA
 * (Faudis' league) already prints on its own official programming sheets --
 * a one-off for that specific organizer, not a general feature. Municipal
 * leagues like his have their own federation branding/NIT that would be
 * meaningless (or actively wrong) on anyone else's export, so this is
 * gated to his account alone (see ALLOWED_EMAIL) rather than exposed to
 * every organizer.
 */
class MunicipalStandingsPdfController extends Controller
{
    private const ALLOWED_EMAIL = 'faudisp@uniguajira.edu.co';

    public function export(CompetitionPhase $phase, StandingsService $standingsService): StreamedResponse|Response
    {
        $this->authorize('view', $phase);

        abort_unless(auth()->user()?->email === self::ALLOWED_EMAIL, 404);
        abort_unless($phase->type === CompetitionPhaseType::League, 404);

        $category = $phase->category;
        $tournament = $phase->tournament;
        $tables = $standingsService->tablesForPhase($phase);

        $pdf = Pdf::loadView('pdf.standings-municipal', [
            'phase' => $phase,
            'category' => $category,
            'tournament' => $tournament,
            'tables' => $tables,
            'lifutguaLogo' => $this->imageAsDataUri('lifutgua.jpg'),
            'difutbolLogo' => $this->imageAsDataUri('difutbol.jpg'),
            'wordmark' => $this->imageAsDataUri('lifutgua-wordmark.png'),
            'signature' => $this->imageAsDataUri('coordinador-firma.jpg'),
        ])->setPaper('letter');

        $fileName = 'tabla-posiciones-'.str($category->name.'-'.$phase->name)->slug().'.pdf';

        return $pdf->download($fileName);
    }

    private function imageAsDataUri(string $fileName): string
    {
        $path = resource_path("images/standings-pdf/{$fileName}");
        $mimeType = str($fileName)->endsWith('.png') ? 'image/png' : 'image/jpeg';

        return "data:{$mimeType};base64,".base64_encode(File::get($path));
    }
}
