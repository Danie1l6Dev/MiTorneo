<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClubRequest;
use App\Models\Club;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class ClubController extends Controller
{
    /**
     * Two ways to browse the same data, picked via ?view= (defaults to
     * "category"): organized by category (and, within it, by group) --
     * how an organizer actually browses "who plays where", since a plain
     * club-by-club list would bury a club fielding several
     * categories/groups -- or organized by club, for "what does THIS club
     * field" instead. Both are built from the same underlying $allTeams
     * query, just grouped differently, so switching views costs no extra
     * queries.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Club::class);

        $view = $request->query('view') === 'club' ? 'club' : 'category';

        // Already ordered youngest-to-oldest -- see User::categories().
        $categories = Auth::user()->categories()
            ->with(['groups' => fn ($query) => $query->orderBy('order')])
            ->get();

        $allTeams = Team::query()
            ->whereIn('category_id', $categories->pluck('id'))
            ->whereNull('tournament_id')
            ->with(['club', 'category', 'group'])
            ->withCount('globalPlayers')
            ->get();

        // Both groupings below read off $allTeams's own order for how their
        // groups come out (the "club" view's per-club category sections in
        // particular) -- sorting it here once covers both instead of each
        // view re-sorting its own grouped result afterward.
        $allTeams = Team::sortedByCategoryAge($allTeams);

        $incompleteTeamIds = Team::idsWithIncompletePlayers($allTeams->pluck('id'));
        $teams = $allTeams->groupBy(['category_id', 'group_id']);

        $clubs = Auth::user()->clubs()->orderBy('name')->get();
        $teamsByClub = $allTeams->groupBy('club_id');

        $clubCount = $clubs->count();

        return view('pages.clubs.index', compact('view', 'categories', 'teams', 'clubs', 'teamsByClub', 'clubCount', 'incompleteTeamIds'));
    }

    public function create(): View
    {
        $this->authorize('create', Club::class);

        $categories = Auth::user()->categories()->with('groups')->get();

        return view('pages.clubs.create', compact('categories'));
    }

    /**
     * Creates the club and, optionally in the same step, one plantel per
     * category (or per group, for a category that uses them) checked in
     * the form -- so a club doesn't have to be created bare and then
     * visited again just to add its first squads. Each plantel's name
     * defaults to the club's own name; nothing here can collide since
     * this club has no existing teams yet.
     */
    public function store(ClubRequest $request): RedirectResponse
    {
        $this->authorize('create', Club::class);

        $validated = $request->validated();

        $club = Auth::user()->clubs()->create(['name' => $validated['name']]);

        foreach ($validated['category_ids'] ?? [] as $categoryId) {
            $this->makeInitialTeam($club, (int) $categoryId, null);
        }

        foreach ($validated['group_selections'] ?? [] as $categoryId => $groupIds) {
            foreach ($groupIds as $groupId) {
                $this->makeInitialTeam($club, (int) $categoryId, (int) $groupId);
            }
        }

        return to_route('clubs.show', $club)->with('status', __('Club creado correctamente.'));
    }

    private function makeInitialTeam(Club $club, int $categoryId, ?int $groupId): void
    {
        $team = new Team(['name' => $club->name]);
        $team->club_id = $club->id;
        $team->category_id = $categoryId;
        $team->group_id = $groupId;
        $team->save();
    }

    public function show(Club $club): View
    {
        $this->authorize('view', $club);

        $club->load(['teams' => fn ($query) => $query->with(['category', 'group'])->orderBy('name')]);
        $club->setRelation('teams', Team::sortedByCategoryAge($club->teams));

        $incompleteTeamIds = Team::idsWithIncompletePlayers($club->teams->pluck('id'));

        // Only categories from THIS organizer's own catalog can ever host a
        // new plantel for this club -- see the age/category rules in
        // docs/plan-reestructuracion/01-clubes-equipos-categorias-globales.md.
        $availableCategories = Auth::user()->categories()->get();

        return view('pages.clubs.show', compact('club', 'availableCategories', 'incompleteTeamIds'));
    }

    public function edit(Club $club): View
    {
        $this->authorize('update', $club);

        return view('pages.clubs.edit', compact('club'));
    }

    public function update(ClubRequest $request, Club $club): RedirectResponse
    {
        $this->authorize('update', $club);

        $club->update($request->validated());

        return to_route('clubs.show', $club)->with('status', __('Club actualizado correctamente.'));
    }

    public function destroy(Club $club): RedirectResponse
    {
        $this->authorize('delete', $club);

        if ($club->teams()->exists()) {
            return back()->with('error', __(
                'No puedes eliminar un club que tiene planteles registrados. Elimina primero sus planteles.'
            ));
        }

        $club->delete();

        return to_route('clubs.index')->with('status', __('Club eliminado correctamente.'));
    }
}
