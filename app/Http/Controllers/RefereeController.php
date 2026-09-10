<?php

namespace App\Http\Controllers;

use App\Http\Requests\RefereeRequest;
use App\Models\Referee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class RefereeController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', Referee::class);

        $referees = Auth::user()->referees()->withCount('matches')->orderBy('full_name')->get();

        return view('pages.referees.index', compact('referees'));
    }

    public function create(): View
    {
        $this->authorize('create', Referee::class);

        return view('pages.referees.create');
    }

    public function store(RefereeRequest $request): RedirectResponse
    {
        $this->authorize('create', Referee::class);

        $referee = Auth::user()->referees()->create($request->validated());

        return to_route('referees.show', $referee)->with('status', __('Árbitro registrado correctamente.'));
    }

    public function show(Referee $referee): View
    {
        $this->authorize('view', $referee);

        $matches = $referee->matches()
            ->with(['tournament', 'category', 'competitionPhase', 'homeTeam', 'awayTeam'])
            ->orderByDesc('scheduled_at')
            ->get();

        return view('pages.referees.show', compact('referee', 'matches'));
    }

    public function edit(Referee $referee): View
    {
        $this->authorize('update', $referee);

        return view('pages.referees.edit', compact('referee'));
    }

    public function update(RefereeRequest $request, Referee $referee): RedirectResponse
    {
        $this->authorize('update', $referee);

        $referee->update($request->validated());

        return to_route('referees.show', $referee)->with('status', __('Árbitro actualizado correctamente.'));
    }
}
