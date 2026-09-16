<?php

namespace App\Http\Controllers;

use App\Enums\SanctionStatus;
use App\Http\Requests\SanctionResolveRequest;
use App\Models\Sanction;
use App\Models\Team;
use App\Models\Tournament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class SanctionController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', Sanction::class);

        $sanctions = Sanction::query()
            ->whereHas('match.tournament', fn ($query) => $query->where('user_id', Auth::id()))
            ->with(['player', 'coach', 'team', 'match.tournament', 'match.category'])
            ->latest('id')
            ->get();

        // Split into the three states the index page actually shows as
        // separate sections -- mutually exclusive and exhaustive for every
        // sanction that isn't in the "shouldn't happen" edge case Sanction's
        // own docblock calls out (resolved with matches_banned still null),
        // same reasoning the stat cards above the list already relied on.
        $pendingSanctions = $sanctions->filter->isPending()->values();
        $activeSanctions = $sanctions->filter->isActive()->values();
        $fulfilledSanctions = $sanctions->filter->isFulfilled()->values();

        $expelledTeams = $this->expelledTeams();

        return view('pages.sanctions.index', compact(
            'sanctions', 'pendingSanctions', 'activeSanctions', 'fulfilledSanctions', 'expelledTeams'
        ));
    }

    /**
     * Every plantel currently expelled from one of this organizer's
     * tournaments (see TeamExpulsionService) -- a fundamentally different
     * kind of sanction from a player/coach's (no pending/served-fechas
     * lifecycle, see Sanction's own docblock), so it's kept out of the
     * Sanction model entirely and shown as its own section on this same
     * page instead. One row per (team, tournament): a global team expelled
     * from one tournament and later re-entered into another shows up only
     * for the tournament it's actually still expelled from.
     *
     * @return array<int, array{team: Team, tournament: Tournament, expelled_at: string, reason: string|null}>
     */
    private function expelledTeams(): array
    {
        $expulsions = [];

        foreach (Auth::user()->tournaments()->get() as $tournament) {
            $expelled = $tournament->globalTeams()
                ->wherePivotNotNull('expelled_at')
                ->with(['category', 'club'])
                ->get();

            foreach ($expelled as $team) {
                $expulsions[] = [
                    'team' => $team,
                    'tournament' => $tournament,
                    'expelled_at' => (string) $team->pivot->getAttribute('expelled_at'),
                    'reason' => $team->pivot->getAttribute('expulsion_reason'),
                ];
            }
        }

        usort($expulsions, fn (array $a, array $b): int => $b['expelled_at'] <=> $a['expelled_at']);

        return $expulsions;
    }

    public function show(Sanction $sanction): View
    {
        $this->authorize('view', $sanction);

        return view('pages.sanctions.show', compact('sanction'));
    }

    public function resolve(SanctionResolveRequest $request, Sanction $sanction): RedirectResponse
    {
        $this->authorize('resolve', $sanction);

        if (! $sanction->isPending()) {
            return back()->with('error', __('Esta sanción ya fue resuelta.'));
        }

        $sanction->update([
            'matches_banned' => $request->validated('matches_banned'),
            'resolution_notes' => $request->validated('resolution_notes'),
            'fine_amount' => $sanction->coach_id !== null ? $request->validated('fine_amount') : null,
            'status' => SanctionStatus::Resolved,
            'resolved_at' => now(),
        ]);

        return to_route('sanctions.show', $sanction)->with('status', __('Sanción resuelta correctamente.'));
    }
}
