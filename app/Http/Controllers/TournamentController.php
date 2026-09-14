<?php

namespace App\Http\Controllers;

use App\Http\Requests\TournamentRequest;
use App\Models\CompetitionPhase;
use App\Models\Tournament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

        $tournament->load('globalCategories');
        $tournament->loadCount(['globalCategories', 'globalTeams', 'matches']);

        // Per global category, how many of its planteles are already
        // registered for this tournament (tournament_team) -- one query
        // instead of one per category card.
        $globalTeamCounts = DB::table('tournament_team')
            ->join('teams', 'teams.id', '=', 'tournament_team.team_id')
            ->where('tournament_team.tournament_id', $tournament->id)
            ->selectRaw('teams.category_id, count(*) as aggregate')
            ->groupBy('teams.category_id')
            ->pluck('aggregate', 'teams.category_id');

        // Categories whose roster is frozen (T02-10): once a category has a
        // phase in this tournament, its tournament_team selection can no
        // longer change, so the card hides the "quitar" action and shows
        // "Ver planteles" (read-only) instead of "Elegir planteles".
        $lockedCategoryIds = CompetitionPhase::query()
            ->where('tournament_id', $tournament->id)
            ->pluck('category_id')
            ->unique();

        return view('pages.tournaments.show', compact('tournament', 'globalTeamCounts', 'lockedCategoryIds'));
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
