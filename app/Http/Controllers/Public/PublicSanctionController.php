<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Sanction;
use App\Models\Team;
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

        // See SanctionController::index() -- the stat cards cover every
        // sanction, player/DT and team expulsion alike, read through the
        // same "has a resolution been attached yet" lens the "ver/por
        // resolución" badge already uses.
        $expelledPendingCount = $expelledTeams->filter(fn (Team $team): bool => ! $team->pivot->getAttribute('expulsion_reason') && ! $team->pivot->getAttribute('expulsion_resolution_pdf_path'))->count();
        $expelledActiveCount = $expelledTeams->count() - $expelledPendingCount;

        $totalPendingCount = $pendingSanctions->count() + $expelledPendingCount;
        $totalActiveCount = $activeSanctions->count() + $expelledActiveCount;
        $totalFulfilledCount = $fulfilledSanctions->count();

        return view('pages.public.sanctions.index', compact(
            'tournament', 'sanctions', 'pendingSanctions', 'activeSanctions', 'fulfilledSanctions', 'expelledTeams',
            'totalPendingCount', 'totalActiveCount', 'totalFulfilledCount'
        ));
    }

    /**
     * Read-only counterpart to SanctionController::show() -- same sanction,
     * no resolve form (that's an organizer-only action, see
     * SanctionPolicy::resolve()). $sanction is bound straight from its own
     * id, so it must actually belong to $tournament or a visitor could
     * reach another organizer's sanction just by changing the id in the
     * URL.
     */
    public function show(Tournament $tournament, Sanction $sanction): View
    {
        abort_unless($sanction->match->tournament_id === $tournament->id, 404);

        $sanction->load(['player', 'coach', 'team', 'match.tournament', 'match.category']);

        return view('pages.public.sanctions.show', compact('tournament', 'sanction'));
    }
}
