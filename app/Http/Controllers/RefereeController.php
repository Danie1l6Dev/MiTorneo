<?php

namespace App\Http\Controllers;

use App\Http\Requests\RefereeRequest;
use App\Models\Referee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function show(Request $request, Referee $referee): View
    {
        $this->authorize('view', $referee);

        // Filter option lists are built from the referee's OWN matches, not
        // every tournament/category the organizer owns -- a referee who's
        // never worked a given tournament shouldn't show it as a choice, and
        // this also means an id belonging to some other tournament/category
        // simply won't match anything below instead of needing a separate
        // ownership check (same pattern already used by the group filter on
        // the phase statistics page).
        $tournaments = $referee->matches()
            ->with('tournament')
            ->get()
            ->pluck('tournament')
            ->unique('id')
            ->sortBy('name')
            ->values();

        $selectedTournament = $tournaments->firstWhere('id', $request->integer('tournament'));

        // Categories are scoped to the selected tournament -- picking a
        // tournament is what narrows which categories can even be chosen.
        $categories = $selectedTournament
            ? $referee->matches()
                ->where('tournament_id', $selectedTournament->id)
                ->with('category')
                ->get()
                ->pluck('category')
                ->unique('id')
                ->sortBy('name')
                ->values()
            : collect();

        $selectedCategory = $categories->firstWhere('id', $request->integer('category'));

        $dateFrom = $request->date('date_from');
        $dateTo = $request->date('date_to');

        $matches = $referee->matches()
            ->with(['tournament', 'category', 'competitionPhase', 'homeTeam', 'awayTeam'])
            ->when($selectedTournament, fn ($query) => $query->where('tournament_id', $selectedTournament->id))
            ->when($selectedCategory, fn ($query) => $query->where('category_id', $selectedCategory->id))
            ->when($dateFrom, fn ($query) => $query->whereDate('scheduled_at', '>=', $dateFrom))
            ->when($dateTo, fn ($query) => $query->whereDate('scheduled_at', '<=', $dateTo))
            ->orderByDesc('scheduled_at')
            ->get();

        return view('pages.referees.show', compact(
            'referee', 'matches', 'tournaments', 'categories',
            'selectedTournament', 'selectedCategory', 'dateFrom', 'dateTo'
        ));
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
