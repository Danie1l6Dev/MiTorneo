<?php

namespace App\Http\Controllers;

use App\Enums\SanctionStatus;
use App\Http\Requests\SanctionResolveRequest;
use App\Models\Sanction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class SanctionController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', Sanction::class);

        $sanctions = Sanction::query()
            ->whereHas('team.tournament', fn ($query) => $query->where('user_id', Auth::id()))
            ->with(['player', 'coach', 'team.tournament', 'match.category'])
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

        return view('pages.sanctions.index', compact(
            'sanctions', 'pendingSanctions', 'activeSanctions', 'fulfilledSanctions'
        ));
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
