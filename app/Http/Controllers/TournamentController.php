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

        $tournament = Auth::user()->tournaments()->make($request->validated());
        $tournament->slug = Tournament::generateUniqueSlug($tournament->name);
        $tournament->save();

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

    /**
     * Manual escape hatch for a public link that's broken, leaked, or (in
     * practice this should never happen, since store() always sets one)
     * simply missing -- generates a brand new, different slug and saves it
     * immediately. The previous link stops working the moment this runs.
     */
    public function regenerateSlug(Tournament $tournament): RedirectResponse
    {
        $this->authorize('update', $tournament);

        $tournament->regenerateSlug();

        return back()->with('status', __('Se generó un nuevo enlace público. El enlace anterior ha dejado de funcionar.'));
    }
}
