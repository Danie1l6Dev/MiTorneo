<?php

namespace App\Http\Controllers;

use App\Http\Requests\GroupRequest;
use App\Models\CompetitionPhase;
use App\Models\Group;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GroupController extends Controller
{
    public function create(CompetitionPhase $phase): View
    {
        $this->authorize('create', [Group::class, $phase]);

        return view('pages.groups.create', compact('phase'));
    }

    public function store(GroupRequest $request, CompetitionPhase $phase): RedirectResponse
    {
        $this->authorize('create', [Group::class, $phase]);

        $group = $phase->groups()->make($request->validated());
        $group->tournament_id = $phase->tournament_id;
        $group->save();

        return to_route('groups.show', $group);
    }

    public function show(Group $group): View
    {
        $this->authorize('view', $group);

        $group->load('teams');

        $availableTeams = $group->competitionPhase->category->teams;

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

        $phase = $group->competitionPhase;

        $group->delete();

        return to_route('phases.show', $phase);
    }

    /**
     * Sync the teams that belong to this group. The available choices are
     * restricted to teams registered in the group's category.
     */
    public function syncTeams(Request $request, Group $group): RedirectResponse
    {
        $this->authorize('update', $group);

        $request->validate([
            'team_ids' => ['array'],
            'team_ids.*' => ['integer', 'exists:teams,id'],
        ]);

        $categoryTeamIds = $group->competitionPhase->category->teams()->pluck('id');
        $teamIds = collect($request->array('team_ids'))->intersect($categoryTeamIds);

        $group->teams()->sync($teamIds);

        return to_route('groups.show', $group);
    }
}
