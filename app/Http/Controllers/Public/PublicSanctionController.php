<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Sanction;
use App\Models\Tournament;
use Illuminate\View\View;

/**
 * The public portal's page for one tournament's sanctions -- read-only,
 * reachable without logging in (see routes/public.php). Same three-state
 * split (pending/active/fulfilled) as the admin equivalent
 * (SanctionController::index()), just scoped to one tournament instead of
 * every tournament an authenticated organizer owns.
 */
class PublicSanctionController extends Controller
{
    public function index(Tournament $tournament): View
    {
        $sanctions = Sanction::query()
            ->whereHas('match', fn ($query) => $query->where('tournament_id', $tournament->id))
            ->with(['player', 'coach', 'team', 'match.category'])
            ->latest('id')
            ->get();

        $pendingSanctions = $sanctions->filter->isPending()->values();
        $activeSanctions = $sanctions->filter->isActive()->values();
        $fulfilledSanctions = $sanctions->filter->isFulfilled()->values();

        // Every plantel currently expelled from THIS tournament -- see
        // TeamExpulsionService. Always via the tournament_team pivot
        // (globalTeams()), regardless of whether the tournament is still
        // legacy or already promoted (T02-01): expulsion never used the
        // legacy per-tournament Team relation.
        $expelledTeams = $tournament->globalTeams()
            ->wherePivotNotNull('expelled_at')
            ->with(['category', 'club'])
            ->get();

        return view('pages.public.sanctions.index', compact(
            'tournament', 'sanctions', 'pendingSanctions', 'activeSanctions', 'fulfilledSanctions', 'expelledTeams'
        ));
    }
}
