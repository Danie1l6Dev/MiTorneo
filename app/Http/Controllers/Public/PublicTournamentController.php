<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Tournament;
use Illuminate\View\View;

/**
 * The public portal's landing page for one tournament -- read-only, reachable
 * without logging in (see routes/public.php). Deliberately has no other
 * action: nothing on this path ever creates, updates, or deletes anything.
 */
class PublicTournamentController extends Controller
{
    public function show(Tournament $tournament): View
    {
        $tournament->load(['categories' => fn ($query) => $query->withCount(['teams', 'groups'])]);
        $tournament->loadCount(['categories', 'teams', 'matches']);

        return view('pages.public.tournaments.show', compact('tournament'));
    }
}
