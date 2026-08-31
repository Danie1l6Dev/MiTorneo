<?php

namespace App\Http\Controllers;

use App\Http\Requests\GroupRequest;
use App\Models\Category;
use App\Models\Group;
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

        $availableTeams = $group->category->teams;

        return view('pages.groups.show', compact('group', 'availableTeams'));
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
     * Assign which of the category's teams belong to this group. Any team
     * removed from the selection is left without a group.
     */
    public function updateTeams(Request $request, Group $group): RedirectResponse
    {
        $this->authorize('update', $group);

        $request->validate([
            'team_ids' => ['array'],
            'team_ids.*' => ['integer', 'exists:teams,id'],
        ]);

        $categoryTeamIds = $group->category->teams()->pluck('id');
        $teamIds = collect($request->array('team_ids'))->intersect($categoryTeamIds);

        $group->category->teams()->whereIn('id', $teamIds)->update(['group_id' => $group->id]);
        $group->category->teams()->where('group_id', $group->id)->whereNotIn('id', $teamIds)->update(['group_id' => null]);

        return to_route('groups.show', $group);
    }
}
