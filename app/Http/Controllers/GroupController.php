<?php

namespace App\Http\Controllers;

use App\Http\Requests\GroupRequest;
use App\Models\Category;
use App\Models\Group;
use App\Models\Team;
use App\Models\Tournament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class GroupController extends Controller
{
    /**
     * Groups belong to one tournament each: every tournament that includes a
     * category defines its own "Grupo A"/"Grupo B" for it, and decides which of
     * its enrolled planteles go in each. create()/store() resolve the tournament
     * the way the category's other legacy routes do (its only tournament);
     * createForTournament()/storeForTournament() take it from the URL, the only
     * way when the category is enrolled in more than one.
     */
    public function create(Category $category): View
    {
        $this->authorize('create', [Group::class, $category]);

        return $this->createForm($category, $category->resolveSoleTournament());
    }

    public function createForTournament(Tournament $tournament, Category $category): View
    {
        $this->authorizeForTournament($tournament, $category);

        return $this->createForm($category, $tournament);
    }

    public function store(GroupRequest $request, Category $category): RedirectResponse
    {
        $this->authorize('create', [Group::class, $category]);

        return $this->storeGroup($request, $category, $category->resolveSoleTournament());
    }

    public function storeForTournament(GroupRequest $request, Tournament $tournament, Category $category): RedirectResponse
    {
        $this->authorizeForTournament($tournament, $category);

        return $this->storeGroup($request, $category, $tournament);
    }

    /**
     * Copies the groups (names and order, never the planteles in them) that
     * this category has in another of the organizer's tournaments into this
     * one, skipping any name it already has -- so a new edition doesn't have
     * to recreate "Grupo A", "Grupo B"... by hand.
     */
    public function copyFromTournament(Request $request, Tournament $tournament, Category $category): RedirectResponse
    {
        $this->authorizeForTournament($tournament, $category);

        $validated = $request->validate([
            'from_tournament_id' => [
                'required',
                'integer',
                Rule::exists('tournaments', 'id')->where('user_id', $tournament->user_id),
                Rule::notIn([$tournament->id]),
            ],
        ]);

        $existing = $category->groupsFor($tournament)->pluck('name')->all();
        $copied = 0;

        foreach (Group::query()->where('category_id', $category->id)->where('tournament_id', $validated['from_tournament_id'])->orderBy('order')->get() as $source) {
            if (in_array($source->name, $existing, true)) {
                continue;
            }

            $group = $category->groups()->make(['name' => $source->name, 'order' => $source->order]);
            $group->tournament_id = $tournament->id;
            $group->save();
            $copied++;
        }

        return to_route('tournaments.categories.show', [$tournament, $category])->with(
            $copied > 0 ? 'status' : 'error',
            $copied > 0
                ? trans_choice('Se copió :count grupo.|Se copiaron :count grupos.', $copied, ['count' => $copied])
                : __('Ese torneo no tiene grupos nuevos para copiar en esta categoría.')
        );
    }

    private function authorizeForTournament(Tournament $tournament, Category $category): void
    {
        $this->authorize('create', [Group::class, $category]);
        $this->authorize('update', $tournament);

        abort_unless($category->tournament_id === $tournament->id || $tournament->globalCategories()->whereKey($category->id)->exists(), 404);
    }

    private function createForm(Category $category, Tournament $tournament): View
    {
        return view('pages.groups.create', compact('category', 'tournament'));
    }

    private function storeGroup(GroupRequest $request, Category $category, Tournament $tournament): RedirectResponse
    {
        $group = $category->groups()->make($request->validated());
        $group->tournament_id = $tournament->id;
        $group->save();

        return to_route('groups.show', $group);
    }

    public function show(Group $group): View
    {
        $this->authorize('view', $group);

        $group->load('teams');

        $unassignedTeams = $this->teamsEnrolledWithoutGroup($group);

        return view('pages.groups.show', compact('group', 'unassignedTeams'));
    }

    /**
     * The planteles enrolled in the group's tournament, in its category, that
     * are not in any of its groups yet.
     *
     * @return Collection<int, Team>
     */
    private function teamsEnrolledWithoutGroup(Group $group): Collection
    {
        return $group->resolvedTournament()
            ->globalTeams()
            ->where('teams.category_id', $group->category_id)
            ->wherePivotNull('group_id')
            ->orderBy('teams.name')
            ->get();
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

        $tournament = $group->resolvedTournament();
        $category = $group->category;

        $group->delete();

        return to_route('tournaments.categories.show', [$tournament, $category]);
    }

    /**
     * Put one plantel enrolled in the group's tournament (and not in a group
     * yet) into this group. Teams already in another group are not eligible
     * here, so this action can never silently steal a team from a different
     * group.
     */
    public function attachTeam(Request $request, Group $group): RedirectResponse
    {
        $this->authorize('update', $group);

        $request->validate([
            'team_id' => ['required', 'integer'],
        ]);

        $team = $this->teamsEnrolledWithoutGroup($group)->firstWhere('id', $request->integer('team_id'));

        abort_if($team === null, 404);

        $team->assignGroupIn($group->resolvedTournament(), $group);

        return to_route('groups.show', $group);
    }

    /**
     * Take one plantel out of this group, leaving it enrolled in the
     * tournament without a group.
     */
    public function detachTeam(Group $group, Team $team): RedirectResponse
    {
        $this->authorize('update', $group);

        abort_unless($group->teams()->whereKey($team->id)->exists(), 403);

        $team->assignGroupIn($group->resolvedTournament(), null);

        return to_route('groups.show', $group);
    }
}
