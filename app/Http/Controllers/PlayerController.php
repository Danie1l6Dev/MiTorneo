<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClubPlayerRequest;
use App\Http\Requests\PlayerRequest;
use App\Models\Club;
use App\Models\MatchEvent;
use App\Models\Player;
use App\Models\Sanction;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PlayerController extends Controller
{
    public function create(Team $team): View
    {
        $this->authorize('create', [Player::class, $team]);

        if (! $team->tournament_id) {
            return view('pages.players.create-for-team', compact('team'));
        }

        return view('pages.players.create', compact('team'));
    }

    public function store(PlayerRequest $request, Team $team): RedirectResponse
    {
        $this->authorize('create', [Player::class, $team]);

        if (! $team->tournament_id) {
            return $this->storeForTeam($request, $team);
        }

        $team->players()->create($request->validated());

        return to_route('teams.show', $team)->with('status', __('Jugador agregado correctamente.'));
    }

    /**
     * A global team (see docs/plan-reestructuracion/01-clubes-equipos-categorias-globales.md)
     * finds-or-links instead of always creating: if a player with this
     * document already exists in this organizer's roster, they're just
     * attached to this team via player_team (age eligibility already
     * checked by PlayerRequest) -- no re-typing their name/birth date. A
     * genuinely new player is created the "old" way (players.team_id
     * pointing straight at this team) since it's their first and only
     * team so far; player_team only comes into play for a 2nd+ one.
     */
    private function storeForTeam(PlayerRequest $request, Team $team): RedirectResponse
    {
        $validated = $request->validated();
        $jerseyNumber = Arr::pull($validated, 'jersey_number');
        $documentNumber = $validated['document_number'] ?? null;

        $existingPlayer = $documentNumber ? Player::findForOrganizer($documentNumber, Auth::id()) : null;

        if ($existingPlayer) {
            // PlayerRequest's blocksJoiningClub() check already confirmed
            // this is only reachable when they're inactive at their
            // current club -- a player only ever belongs to ONE club at a
            // time, so moving them here means every old team link goes,
            // not just adding this one alongside it.
            if ($team->club_id !== null && $existingPlayer->currentClub()?->id !== $team->club_id) {
                $existingPlayer->teams()->detach();
                $existingPlayer->team_id = $team->id;
                $existingPlayer->jersey_number = $jerseyNumber;
                $existingPlayer->is_active = true;
                $existingPlayer->save();

                return to_route('teams.show', $team)->with('status', __(
                    ':name se movió a este club, a este plantel.',
                    ['name' => $existingPlayer->full_name]
                ));
            }

            $existingPlayer->teams()->attach($team->id, ['jersey_number' => $jerseyNumber]);

            return to_route('teams.show', $team)->with('status', __(
                ':name ya estaba registrado -- se vinculó a este plantel sin duplicar sus datos.',
                ['name' => $existingPlayer->full_name]
            ));
        }

        $player = new Player($validated);
        $player->jersey_number = $jerseyNumber;
        $player->team_id = $team->id;
        $player->save();

        return to_route('teams.show', $team)->with('status', __('Jugador agregado correctamente.'));
    }

    /**
     * The club-level "agregar jugador" flow: pick one or more of the
     * club's own planteles to enroll them in at once (checkboxes gated by
     * age eligibility client-side, re-checked authoritatively by
     * ClubPlayerRequest). This is what actually lets a kid go straight
     * into two categories in one step, instead of repeating the
     * single-team flow above once per plantel.
     */
    public function createForClub(Club $club): View
    {
        $this->authorize('create', [Player::class, $club]);

        $teams = Team::sortedByCategoryAge($club->teams()->with(['category', 'group'])->orderBy('name')->get());

        return view('pages.clubs.players.create', compact('club', 'teams'));
    }

    public function storeForClub(ClubPlayerRequest $request, Club $club): RedirectResponse
    {
        $this->authorize('create', [Player::class, $club]);

        $validated = $request->validated();
        $teamIds = $validated['team_ids'];
        $documentNumber = $validated['document_number'] ?? null;

        $existingPlayer = $documentNumber ? Player::findForOrganizer($documentNumber, Auth::id()) : null;

        if ($existingPlayer) {
            // ClubPlayerRequest's blocksJoiningClub() check already
            // confirmed this is only reachable when they're inactive at
            // their current club -- a player only ever belongs to ONE
            // club at a time, so moving them here drops every old team
            // link instead of adding these alongside it.
            if ($existingPlayer->currentClub()?->id !== $club->id) {
                $existingPlayer->teams()->detach();
                $newPrimaryTeamId = array_shift($teamIds);
                $existingPlayer->team_id = $newPrimaryTeamId;
                $existingPlayer->is_active = true;
                $existingPlayer->save();

                if ($teamIds !== []) {
                    $existingPlayer->teams()->attach($teamIds);
                }

                return to_route('clubs.show', $club)->with('status', __(
                    ':name se movió a este club.', ['name' => $existingPlayer->full_name]
                ));
            }

            $alreadyLinkedIds = $existingPlayer->teams()->pluck('teams.id')
                ->push($existingPlayer->team_id)
                ->all();

            $newTeamIds = array_diff($teamIds, $alreadyLinkedIds);
            $existingPlayer->teams()->attach($newTeamIds);

            return to_route('clubs.show', $club)->with('status', __(
                ':name ya estaba registrado -- se vinculó a :count plantel(es) nuevo(s) sin duplicar sus datos.',
                ['name' => $existingPlayer->full_name, 'count' => count($newTeamIds)]
            ));
        }

        $primaryTeamId = array_shift($teamIds);

        $player = new Player([
            'full_name' => $validated['full_name'],
            'document_number' => $documentNumber,
            'birth_date' => $validated['birth_date'],
        ]);
        $player->team_id = $primaryTeamId;
        $player->save();

        if ($teamIds !== []) {
            $player->teams()->attach($teamIds);
        }

        return to_route('clubs.show', $club)->with('status', __('Jugador agregado correctamente.'));
    }

    public function edit(Player $player): View
    {
        $this->authorize('update', $player);

        // One combined list for the view: planteles this player is already
        // on (shown checked and locked -- this form only ever adds a
        // membership, see update() below) alongside the ones they could
        // still join now that their birth_date unlocks the age check (the
        // backfill-era gap T01-11/T01-27 is about) -- see
        // Player::candidateTeamsForEnrollment(). Mixing both into one list
        // reads as "this player's planteles", not just "what's missing".
        $player->load(['team.category', 'team.club', 'teams.category', 'teams.club']);
        $currentTeams = $player->allTeams();
        $clubTeams = Team::sortedByCategoryAge($currentTeams->merge($player->candidateTeamsForEnrollment()));

        return view('pages.players.edit', compact('player', 'clubTeams', 'currentTeams'));
    }

    public function update(PlayerRequest $request, Player $player): RedirectResponse
    {
        $this->authorize('update', $player);

        // birth_date/full_name/document_number/jersey_number are saved
        // unconditionally, before team_ids is even looked at: a backfilled
        // player finally getting their birth_date filled in must always
        // stick, even if an extra plantel checkbox picked alongside it
        // turns out not to be a real candidate (see below) -- see
        // PlayerRequest::rules()'s docblock for why that check doesn't live
        // in the FormRequest itself.
        $validated = $request->validated();
        $player->update($validated);

        // The already-current checkboxes are rendered checked+disabled and
        // are never meant to be submitted (see the view's comment), but a
        // stale resubmission can still carry one in team_ids -- silently
        // dropping anything the player is already on (instead of treating
        // it as "not a real candidate") is what keeps that harmless instead
        // of surfacing a bogus error for a plantel they already have.
        $alreadyLinkedIds = $player->allTeams()->pluck('id');

        $requestedTeamIds = collect($request->input('team_ids', []))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->diff($alreadyLinkedIds);

        if ($requestedTeamIds->isEmpty()) {
            return to_route('teams.show', $player->team)->with('status', __('Jugador actualizado correctamente.'));
        }

        $candidates = $player->candidateTeamsForEnrollment()->keyBy('id');
        $linkableIds = $requestedTeamIds->filter(
            fn ($id) => $candidates->has($id) && $player->ageEligibleForCategory($candidates->get($id)->category)
        );

        if ($linkableIds->isNotEmpty()) {
            $player->teams()->syncWithoutDetaching($linkableIds);
        }

        $status = $linkableIds->isNotEmpty()
            ? __('Jugador actualizado y vinculado a :count plantel(es) más.', ['count' => $linkableIds->count()])
            : __('Jugador actualizado correctamente.');

        if ($requestedTeamIds->count() > $linkableIds->count()) {
            return back()->withInput()->with('status', $status)->withErrors([
                'team_ids' => __('No se pudo sumar a algún plantel elegido: no es de este club o su fecha de nacimiento no lo permite.'),
            ]);
        }

        return to_route('teams.show', $player->team)->with('status', $status);
    }

    /**
     * Detaches one EXTRA plantel (a player_team row) from this player --
     * never their primary team_id, which isn't a pivot row and has no
     * "detach" of its own; see destroyFromClub() for removing a player
     * from a club entirely. Safe unconditionally: any match_events/
     * sanctions already on record for that team reference team_id/
     * player_id directly, never this pivot row, so nothing historical is
     * at risk here.
     */
    public function detachTeam(Player $player, Team $team): RedirectResponse
    {
        $this->authorize('update', $player);

        $player->teams()->detach($team->id);

        return to_route('players.edit', $player)->with('status', __('Jugador quitado de ese plantel.'));
    }

    /**
     * Removes every plantel this player has at $club -- both the pivot
     * ones and, if it belongs to $club, the primary team_id. Blocked
     * outright (rather than silently discarding it) when the player has
     * any goal/card event or sanction on record for one of $club's teams:
     * a real administrative/statistical record, never safe to lose just
     * because someone left the club -- "Desactivar" is what preserves
     * that history while still pulling them out of active rosters.
     *
     * When team_id itself belongs to $club, the player can't simply be
     * left without one (it's required): if they have another team left
     * elsewhere (a different club under the same organizer -- rare, but
     * the schema allows it), that becomes their new primary instead; if
     * this club was genuinely all they had, the player row itself is
     * removed.
     */
    public function destroyFromClub(Club $club, Player $player): RedirectResponse
    {
        $this->authorize('delete', $player);

        $clubTeamIds = collect([$player->team_id])
            ->merge($player->teams()->pluck('teams.id'))
            ->unique()
            ->filter(fn (int $teamId): bool => Team::query()->whereKey($teamId)->where('club_id', $club->id)->exists())
            ->values();

        if ($clubTeamIds->isEmpty()) {
            abort(404);
        }

        $hasHistory = MatchEvent::query()->where('player_id', $player->id)->whereIn('team_id', $clubTeamIds)->exists()
            || Sanction::query()->where('player_id', $player->id)->whereIn('team_id', $clubTeamIds)->exists();

        if ($hasHistory) {
            return back()->with('error', __(
                'No se puede eliminar a :name de :club: ya tiene goles, tarjetas o sanciones registradas en este club. Desactivalo en su lugar para conservar ese historial.',
                ['name' => $player->full_name, 'club' => $club->name]
            ));
        }

        $remainingTeamId = $player->teams()->whereNotIn('teams.id', $clubTeamIds)->value('teams.id');

        DB::transaction(function () use ($player, $clubTeamIds, $remainingTeamId): void {
            $player->teams()->detach($clubTeamIds);

            if (! $clubTeamIds->contains($player->team_id)) {
                return;
            }

            if ($remainingTeamId !== null) {
                $player->team_id = $remainingTeamId;
                $player->save();
                $player->teams()->detach($remainingTeamId);
            } else {
                $player->delete();
            }
        });

        return to_route('clubs.show', $club)->with('status', __(':name eliminado del club.', ['name' => $player->full_name]));
    }

    /**
     * Reactivating a player re-checks the same "unique among active
     * teammates" rule PlayerRequest enforces on create/edit -- their number
     * or document could have been taken by someone else while they were
     * inactive. Both fields are optional, though, so each is only checked
     * when this player actually has a value for it: a bare `where(column,
     * null)` compiles to `column IS NULL`, which would otherwise flag any
     * other teammate who also left theirs blank as a false conflict.
     */
    public function toggleActive(Player $player): RedirectResponse
    {
        $this->authorize('update', $player);

        if (! $player->is_active) {
            $hasJerseyNumber = $player->jersey_number !== null;
            $hasDocumentNumber = $player->document_number !== null;

            $conflict = ($hasJerseyNumber || $hasDocumentNumber) && Player::query()
                ->where('team_id', $player->team_id)
                ->where('is_active', true)
                ->where(function ($query) use ($player, $hasJerseyNumber, $hasDocumentNumber) {
                    if ($hasJerseyNumber) {
                        $query->orWhere('jersey_number', $player->jersey_number);
                    }

                    if ($hasDocumentNumber) {
                        $query->orWhere('document_number', $player->document_number);
                    }
                })
                ->exists();

            if ($conflict) {
                return back()->with('error', __(
                    'No se puede reactivar a :name: su dorsal o documento ya está en uso por otro jugador activo de este equipo.',
                    ['name' => $player->full_name]
                ));
            }
        }

        $player->is_active = ! $player->is_active;
        $player->save();

        return back()->with('status', __('Estado del jugador actualizado.'));
    }
}
