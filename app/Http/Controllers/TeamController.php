<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClubTeamRequest;
use App\Http\Requests\TeamRequest;
use App\Models\Category;
use App\Models\Club;
use App\Models\Team;
use App\Services\PlayerRosterService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
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

    /**
     * A new plantel under a global Club -- see ClubTeamRequest's docblock.
     * Kept as a separate method (rather than overloading create()/store())
     * because the parent here is a Club, not a Category.
     */
    public function createForClub(Club $club): View
    {
        $this->authorize('create', [Team::class, $club]);

        $categories = Auth::user()->categories()->with('groups')->get();

        return view('pages.clubs.teams.create', compact('club', 'categories'));
    }

    public function storeForClub(ClubTeamRequest $request, Club $club): RedirectResponse
    {
        $this->authorize('create', [Team::class, $club]);

        $validated = $request->validated();
        $categoryId = Arr::pull($validated, 'category_id');
        $groupId = Arr::pull($validated, 'group_id');

        $team = new Team($validated);
        $team->club_id = $club->id;
        $team->category_id = $categoryId;
        $team->group_id = $groupId ?: null;
        $team->tournament_id = null;
        $team->save();

        return to_route('clubs.show', $club)->with('status', __('Plantel creado correctamente.'));
    }

    public function show(Team $team): View
    {
        $this->authorize('view', $team);

        $team->load(['category', 'group', 'coach']);

        // A global Team's roster can have players from two sources: ones
        // linked the "old" way (players.team_id, still how PlayerController
        // adds a player -- fine for a team that only ever plays in one
        // category) and ones linked via player_team (backfilled real
        // rosters, or a player deliberately added to more than one
        // plantel). A legacy per-tournament Team only ever has the first
        // kind. See docs/plan-reestructuracion/01-clubes-equipos-categorias-globales.md.
        $roster = $team->players()->orderByDesc('is_active')->orderBy('jersey_number')->get();

        if (! $team->tournament_id) {
            $roster = $roster->merge($team->globalPlayers()->get())->unique('id')->values();
        }

        $activePlayersCount = $roster->where('is_active', true)->count();

        // Every tournament this global team is currently expelled from --
        // see TeamExpulsionService. Empty for a legacy per-tournament team,
        // which is never linked through the tournament_team pivot at all.
        $expulsions = $team->tournaments()->wherePivotNotNull('expelled_at')->get();

        return view('pages.teams.show', compact('team', 'roster', 'activePlayersCount', 'expulsions'));
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

        // Deleting a plantel deletes its players, and with them their goals,
        // cards and sanctions -- a real record, never safe to lose just because
        // the plantel is no longer wanted. Blocked instead of silently cascading.
        if ($team->hasRecordsThatWouldBeLost()) {
            return back()->with('error', __(
                'No se puede eliminar este plantel: ya tiene goles, tarjetas o sanciones registradas (propias o de sus jugadores) y borrarlo las eliminaría.'
            ));
        }

        $category = $team->category;
        $club = $team->club;

        // The players still on it keep their history: its lines close as "plantel eliminado".
        app(PlayerRosterService::class)->closeTeam($team);

        $team->delete();

        return $club
            ? to_route('clubs.show', $club)
            : to_route('categories.show', $category);
    }
}
