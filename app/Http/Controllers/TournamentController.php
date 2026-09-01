<?php

namespace App\Http\Controllers;

use App\Http\Requests\TournamentRequest;
use App\Models\Tournament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class TournamentController extends Controller
{
    public function create(): View
    {
        $this->authorize('create', Tournament::class);

        return view('pages.tournaments.create');
    }

    public function store(TournamentRequest $request): RedirectResponse
    {
        $this->authorize('create', Tournament::class);

        $tournament = Auth::user()->tournaments()->create($request->validated());

        return to_route('tournaments.show', $tournament);
    }

    public function show(Tournament $tournament): View
    {
        $this->authorize('view', $tournament);

        $tournament->load(['categories' => fn ($query) => $query->withCount(['teams', 'groups'])]);
        $tournament->loadCount(['categories', 'teams', 'matches']);

        return view('pages.tournaments.show', compact('tournament'));
    }

    public function edit(Tournament $tournament): View
    {
        $this->authorize('update', $tournament);

        return view('pages.tournaments.edit', compact('tournament'));
    }

    public function update(TournamentRequest $request, Tournament $tournament): RedirectResponse
    {
        $this->authorize('update', $tournament);

        $tournament->update($request->validated());

        return to_route('tournaments.show', $tournament);
    }

    public function destroy(Tournament $tournament): RedirectResponse
    {
        $this->authorize('delete', $tournament);

        $tournament->delete();

        return to_route('dashboard');
    }
}
