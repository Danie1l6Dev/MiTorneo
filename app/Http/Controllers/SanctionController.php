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
            // 'pending' sorts before 'resolved' alphabetically, which is
            // also the order that needs the organizer's attention first.
            ->orderBy('status')
            ->latest('id')
            ->get();

        return view('pages.sanctions.index', compact('sanctions'));
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
