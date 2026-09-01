<?php

namespace App\Http\Controllers;

use App\Http\Requests\GroupRequest;
use App\Models\Category;
use App\Models\Group;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GroupController extends Controller
{
    public function create(Category $category): View
    {
        $this->authorize('create', [Group::class, $category]);

        return view('pages.groups.create', compact('category'));
    }

    public function store(GroupRequest $request, Category $category): RedirectResponse
    {
        $this->authorize('create', [Group::class, $category]);

        $group = $category->groups()->make($request->validated());
        $group->tournament_id = $category->tournament_id;
        $group->save();

        return to_route('groups.show', $group);
    }

    public function show(Group $group): View
    {
        $this->authorize('view', $group);

        $group->load('teams');

        $unassignedTeams = $group->category->teams()->whereNull('group_id')->orderBy('name')->get();

        return view('pages.groups.show', compact('group', 'unassignedTeams'));
    }

    public function edit(Group $group): View
    {
        $this->authorize('update', $group);

        return view('pages.groups.edit', compact('group'));
    }

    public function update(GroupRequest $request, Group $group): RedirectResponse
    {
        $this->authorize('update', $group);

        $group->update($request->validated());

        return to_route('groups.show', $group);
    }

    public function destroy(Group $group): RedirectResponse
    {
        $this->authorize('delete', $group);

        if ($group->teams()->exists() || $group->matches()->exists()) {
            return back()->with('error', __(
                'No puedes eliminar un grupo que tiene equipos o partidos asignados. Quita primero esas asignaciones.'
            ));
        }

        $category = $group->category;

        $group->delete();

        return to_route('categories.show', $category);
    }

    /**
     * Assign one currently unassigned team of the group's category to this
     * group. Teams already in another group are not eligible here — moving
     * a team between groups is done from the team's own edit form, so this
     * action can never silently steal a team from a different group.
     */
    public function attachTeam(Request $request, Group $group): RedirectResponse
    {
        $this->authorize('update', $group);

        $request->validate([
            'team_id' => ['required', 'integer'],
        ]);

        $team = $group->category->teams()->whereNull('group_id')->findOrFail($request->integer('team_id'));

        $team->group_id = $group->id;
        $team->save();

        return to_route('groups.show', $group);
    }

    /**
     * Remove one team from this group, leaving it unassigned.
     */
    public function detachTeam(Group $group, Team $team): RedirectResponse
    {
        $this->authorize('update', $group);

        abort_unless($team->group_id === $group->id, 403);

        $team->group_id = null;
        $team->save();

        return to_route('groups.show', $group);
    }
}
