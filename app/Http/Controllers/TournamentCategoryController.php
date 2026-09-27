<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\CompetitionPhase;
use App\Models\Team;
use App\Models\Tournament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * "Inscripción" of a tournament: picking which of the organizer's global
 * categories (and, within each, which existing planteles) take part in
 * THIS tournament -- via the tournament_category/tournament_team pivots,
 * see docs/plan-reestructuracion/01-clubes-equipos-categorias-globales.md
 * (T01-24/T01-25) and
 * docs/plan-reestructuracion/02-unificacion-categorias-torneo.md (T02-03,
 * which taught the phase/schedule engine to draw its roster from
 * tournament_team).
 */
class TournamentCategoryController extends Controller
{
    /**
     * Once a category already has a phase in THIS tournament, its roster
     * (tournament_team) is frozen: the phase's bracket/schedule/groups were
     * already drawn from that exact team list, so adding or removing a
     * team afterward would leave matches referencing a team that's no
     * longer "in" the tournament (or a phase missing a team that is). See
     * docs/plan-reestructuracion/02-unificacion-categorias-torneo.md
     * (T02-10).
     */
    private function hasStartedPhase(Tournament $tournament, Category $category): bool
    {
        return CompetitionPhase::query()
            ->where('tournament_id', $tournament->id)
            ->where('category_id', $category->id)
            ->exists();
    }

    public function create(Tournament $tournament): View
    {
        $this->authorize('update', $tournament);

        $alreadyIncluded = $tournament->globalCategories()->pluck('categories.id');

        $availableCategories = Auth::user()->categories()
            ->whereNotIn('id', $alreadyIncluded)
            ->get();

        // Distinguishes, for the empty state, "you have no categories at
        // all yet" (offer to create one) from "you already added every
        // category you have" (nothing to create here -- send them to the
        // catalog instead).
        $hasAnyCategories = Auth::user()->categories()->exists();

        return view('pages.tournaments.categories.create', compact('tournament', 'availableCategories', 'hasAnyCategories'));
    }

    public function store(Request $request, Tournament $tournament): RedirectResponse
    {
        $this->authorize('update', $tournament);

        $validated = $request->validate([
            'category_ids' => ['required', 'array', 'min:1'],
            'category_ids.*' => [
                'integer',
                Rule::exists('categories', 'id')->where('user_id', Auth::id())->whereNull('tournament_id'),
            ],
        ]);

        $tournament->globalCategories()->syncWithoutDetaching($validated['category_ids']);

        return to_route('tournaments.show', $tournament)->with('status', __('Categorías agregadas al torneo.'));
    }

    /**
     * Dropping a category from a tournament also drops whichever of its
     * planteles were registered here -- their tournament_team rows would
     * otherwise dangle for a category the tournament no longer includes.
     */
    public function destroy(Tournament $tournament, Category $category): RedirectResponse
    {
        $this->authorize('update', $tournament);

        if ($this->hasStartedPhase($tournament, $category)) {
            return back()->with('error', __(
                'No se puede quitar :category de este torneo: ya tiene una fase iniciada.',
                ['category' => $category->name]
            ));
        }

        $teamIds = Team::query()->where('category_id', $category->id)->pluck('id');
        $tournament->globalTeams()->detach($teamIds);
        $tournament->globalCategories()->detach($category->id);

        // Its groups were this tournament's own and, with no phase started, have no matches.
        $category->groupsFor($tournament)->delete();

        return back()->with('status', __('Categoría quitada del torneo.'));
    }

    /**
     * This tournament's own edition of a catalog category: its groups and
     * phases -- both tournament-scoped (see Group::resolvedTournament() and
     * CompetitionPhase's own $tournament_id) -- and the roster enrolled
     * here. The category's own template fields (name, age range, "usa
     * grupos") are organizer-wide, not tied to this tournament, so they
     * stay on categories.show (reached from the sidebar catalog); this page
     * only links there to edit them.
     */
    public function show(Tournament $tournament, Category $category): View
    {
        $this->authorize('view', $tournament);

        $belongsToTournament = $category->tournament_id
            ? $category->tournament_id === $tournament->id
            : $tournament->globalCategories()->whereKey($category->id)->exists();

        abort_unless($belongsToTournament, 404);

        $groups = $category->groupsFor($tournament)->withCount('teams')->get();

        $teams = $category->teamsForTournament($tournament);

        // Other tournaments of this organizer where the category already has groups: a
        // new edition can copy them instead of recreating them.
        $copyableTournaments = $groups->isEmpty() && $category->uses_groups
            ? Tournament::query()
                ->where('user_id', $tournament->user_id)
                ->whereKeyNot($tournament->id)
                ->whereHas('groups', fn ($query) => $query->where('category_id', $category->id))
                ->orderByDesc('id')
                ->get()
            : collect();

        $phases = CompetitionPhase::query()
            ->where('tournament_id', $tournament->id)
            ->where('category_id', $category->id)
            ->withCount('matches')
            ->orderBy('order')
            ->get();

        $locked = $this->hasStartedPhase($tournament, $category);

        return view('pages.tournaments.categories.show', compact('tournament', 'category', 'groups', 'teams', 'phases', 'locked', 'copyableTournaments'));
    }

    public function editTeams(Tournament $tournament, Category $category): View
    {
        $this->authorize('update', $tournament);

        abort_unless($tournament->globalCategories()->whereKey($category->id)->exists(), 404);

        $teams = Team::query()
            ->where('category_id', $category->id)
            ->with('club')
            ->get()
            ->sortBy(fn (Team $team) => $team->club->name);

        // Which planteles are enrolled here, and the group each one plays in
        // (a group belongs to this tournament: the same plantel can be in
        // another group of another tournament).
        $enrolled = $tournament->globalTeams()->where('teams.category_id', $category->id)->get();
        $selectedIds = $enrolled->pluck('id')->all();
        $groupByTeam = $enrolled->mapWithKeys(fn (Team $team): array => [$team->id => $team->pivot->group_id])->all();
        $groups = $category->uses_groups ? $category->groupsFor($tournament)->get() : collect();
        $locked = $this->hasStartedPhase($tournament, $category);

        return view('pages.tournaments.categories.teams', compact('tournament', 'category', 'teams', 'selectedIds', 'groupByTeam', 'groups', 'locked'));
    }

    /**
     * Replaces this tournament's whole plantel selection for $category in
     * one go -- only for THIS category's teams; other categories' own
     * tournament_team rows are untouched.
     */
    public function updateTeams(Request $request, Tournament $tournament, Category $category): RedirectResponse
    {
        $this->authorize('update', $tournament);

        abort_unless($tournament->globalCategories()->whereKey($category->id)->exists(), 404);

        if ($this->hasStartedPhase($tournament, $category)) {
            return back()->with('error', __(
                'No se pueden modificar los planteles de :category: ya tiene una fase iniciada en este torneo.',
                ['category' => $category->name]
            ));
        }

        $validated = $request->validate([
            'team_ids' => ['nullable', 'array'],
            'team_ids.*' => [
                'integer',
                Rule::exists('teams', 'id')->where('category_id', $category->id),
            ],
            // team id => group id, one entry per plantel that was given a group.
            'groups' => ['nullable', 'array'],
            'groups.*' => [
                'nullable',
                'integer',
                Rule::exists('groups', 'id')->where('category_id', $category->id)->where('tournament_id', $tournament->id),
            ],
        ]);

        $selectedIds = collect($validated['team_ids'] ?? [])->map(fn ($id): int => (int) $id)->unique();
        $groupsByTeam = $category->uses_groups ? ($validated['groups'] ?? []) : [];

        $categoryTeamIds = Team::query()->where('category_id', $category->id)->pluck('id');
        $alreadyEnrolled = $tournament->globalTeams()->whereIn('teams.id', $categoryTeamIds)->pluck('teams.id');

        // Only what changes is touched: a plantel that stays enrolled keeps its
        // row (and with it an expulsion recorded there).
        $tournament->globalTeams()->detach($alreadyEnrolled->diff($selectedIds)->all());

        foreach ($selectedIds as $teamId) {
            $groupId = ($groupsByTeam[$teamId] ?? null) ?: null;

            if ($alreadyEnrolled->contains($teamId)) {
                $tournament->globalTeams()->updateExistingPivot($teamId, ['group_id' => $groupId]);
            } else {
                $tournament->globalTeams()->attach($teamId, ['group_id' => $groupId]);
            }
        }

        return to_route('tournaments.categories.show', [$tournament, $category])->with('status', __('Planteles actualizados para :category.', ['category' => $category->name]));
    }
}
