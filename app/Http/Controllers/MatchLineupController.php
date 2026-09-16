<?php

namespace App\Http\Controllers;

use App\Http\Requests\MatchLineupRequest;
use App\Models\MatchEvent;
use App\Models\MatchLineup;
use App\Models\TournamentMatch;
use Illuminate\Http\RedirectResponse;

class MatchLineupController extends Controller
{
    /**
     * Adds one or more players to this match's lineup for one side at a
     * time -- the search panel's checkboxes all submit together, one
     * team_id shared across the whole batch (see
     * resources/views/components/ui/match-lineup-search.blade.php).
     * Already-called-up players are silently skipped rather than erroring,
     * since the panel's own candidate list already excludes them and a
     * resubmit (e.g. a double click) shouldn't be treated as a mistake.
     */
    public function store(MatchLineupRequest $request, TournamentMatch $match): RedirectResponse
    {
        $this->authorize('create', [MatchLineup::class, $match]);

        if ($match->isLockedByExpulsion()) {
            return to_route('matches.edit', $match)->with('error', $match->expulsionLockMessage());
        }

        $teamId = (int) $request->validated('team_id');

        foreach ($request->validated('player_ids') as $playerId) {
            MatchLineup::query()->firstOrCreate(
                ['match_id' => $match->id, 'player_id' => $playerId],
                ['team_id' => $teamId],
            );
        }

        return to_route('matches.edit', $match)->with('status', __('Convocatoria actualizada.'));
    }

    /**
     * A player who already has an event recorded in this match can't be
     * quietly dropped from the lineup -- that event would be left pointing
     * at someone the match roster no longer says played, the same
     * orphaning concern MatchEventController already guards elsewhere. The
     * fix is the same one already used there too: delete the event(s)
     * first, then the lineup entry.
     */
    public function destroy(MatchLineup $lineup): RedirectResponse
    {
        $this->authorize('delete', $lineup);

        $match = $lineup->match;

        if ($match->isLockedByExpulsion()) {
            return to_route('matches.edit', $match)->with('error', $match->expulsionLockMessage());
        }

        $hasEvents = MatchEvent::query()
            ->where('match_id', $lineup->match_id)
            ->where('player_id', $lineup->player_id)
            ->exists();

        if ($hasEvents) {
            return to_route('matches.edit', $match)->with('error', __(
                'No se puede quitar a :name de la convocatoria: ya tiene eventos registrados en este partido. Elimínalos primero.',
                ['name' => $lineup->player->full_name]
            ));
        }

        $lineup->delete();

        return to_route('matches.edit', $match)->with('status', __('Jugador quitado de la convocatoria.'));
    }
}
