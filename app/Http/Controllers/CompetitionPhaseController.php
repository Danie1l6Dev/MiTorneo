<?php

namespace App\Http\Controllers;

use App\Http\Requests\CompetitionPhaseRequest;
use App\Models\Category;
use App\Models\CompetitionPhase;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class CompetitionPhaseController extends Controller
{
    public function create(Category $category): View
    {
        $this->authorize('create', [CompetitionPhase::class, $category]);

        return view('pages.phases.create', compact('category'));
    }

    public function store(CompetitionPhaseRequest $request, Category $category): RedirectResponse
    {
        $this->authorize('create', [CompetitionPhase::class, $category]);

        $phase = $category->competitionPhases()->make($request->validated());
        $phase->tournament_id = $category->tournament_id;
        $phase->save();

        return to_route('phases.show', $phase);
    }

    public function show(CompetitionPhase $phase): View
    {
        $this->authorize('view', $phase);

        $phase->load(['matches.homeTeam', 'matches.awayTeam']);

        return view('pages.phases.show', compact('phase'));
    }

    public function edit(CompetitionPhase $phase): View
    {
        $this->authorize('update', $phase);

        return view('pages.phases.edit', compact('phase'));
    }

    public function update(CompetitionPhaseRequest $request, CompetitionPhase $phase): RedirectResponse
    {
        $this->authorize('update', $phase);

        $phase->update($request->validated());

        return to_route('phases.show', $phase);
    }

    public function destroy(CompetitionPhase $phase): RedirectResponse
    {
        $this->authorize('delete', $phase);

        $category = $phase->category;

        $phase->delete();

        return to_route('categories.show', $category);
    }
}
