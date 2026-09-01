<?php

namespace App\Http\Controllers;

use App\Http\Requests\TeamRequest;
use App\Models\Category;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\View\View;

class TeamController extends Controller
{
    public function create(Category $category, Request $request): View
    {
        $this->authorize('create', [Team::class, $category]);

        $lockedGroup = $category->uses_groups
            ? $category->groups()->find($request->integer('group'))
            : null;

        return view('pages.teams.create', compact('category', 'lockedGroup'));
    }

    public function store(TeamRequest $request, Category $category): RedirectResponse
    {
        $this->authorize('create', [Team::class, $category]);

        $validated = $request->validated();
        $groupId = Arr::pull($validated, 'group_id');

        $team = $category->teams()->make($validated);
        $team->tournament_id = $category->tournament_id;
        $team->group_id = $groupId;
        $team->save();

        return $groupId
            ? to_route('groups.show', $groupId)
            : to_route('categories.show', $category);
    }

    public function edit(Team $team): View
    {
        $this->authorize('update', $team);

        return view('pages.teams.edit', compact('team'));
    }

    public function update(TeamRequest $request, Team $team): RedirectResponse
    {
        $this->authorize('update', $team);

        $validated = $request->validated();
        $groupId = Arr::pull($validated, 'group_id');

        $team->fill($validated);
        $team->group_id = $groupId;
        $team->save();

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
