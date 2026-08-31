<?php

namespace App\Http\Controllers;

use App\Http\Requests\TeamRequest;
use App\Models\Category;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class TeamController extends Controller
{
    public function create(Category $category): View
    {
        $this->authorize('create', [Team::class, $category]);

        return view('pages.teams.create', compact('category'));
    }

    public function store(TeamRequest $request, Category $category): RedirectResponse
    {
        $this->authorize('create', [Team::class, $category]);

        $team = $category->teams()->make($request->validated());
        $team->tournament_id = $category->tournament_id;
        $team->save();

        return to_route('categories.show', $category);
    }

    public function edit(Team $team): View
    {
        $this->authorize('update', $team);

        return view('pages.teams.edit', compact('team'));
    }

    public function update(TeamRequest $request, Team $team): RedirectResponse
    {
        $this->authorize('update', $team);

        $team->update($request->validated());

        return to_route('categories.show', $team->category);
    }

    public function destroy(Team $team): RedirectResponse
    {
        $this->authorize('delete', $team);

        $category = $team->category;

        $team->delete();

        return to_route('categories.show', $category);
    }
}
