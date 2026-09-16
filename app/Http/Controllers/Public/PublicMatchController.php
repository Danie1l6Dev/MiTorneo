<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Sanction;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * The public portal's page for one match -- read-only, reachable without
 * logging in (see routes/public.php). Shows the same match-level detail the
 * admin edit page reads (events, sanctions, unavailable players), just never
 * anything that mutates it: no result form, no quick-add roster panels, no
 * referee assignment.
 */
class PublicMatchController extends Controller
{
    public function show(Tournament $tournament, TournamentMatch $match): View
    {
        // $match is bound straight from its own id, so a mismatched pair in
        // the URL must 404 instead of silently showing a match from a
        // different tournament.
        if ($match->tournament_id !== $tournament->id) {
            abort(404);
        }

        $match->load([
            'homeTeam.coach',
            'awayTeam.coach',
            'competitionPhase',
            'category',
            'referee',
            'firstLeg',
            // Ordered by registration order, not minute -- see
            // TournamentMatchController::edit()'s own docblock: minute isn't
            // collected today, so it wouldn't produce a meaningful chronology.
            'events' => fn ($query) => $query->with(['player', 'coach'])->orderBy('id'),
        ]);

        $homeEventGroups = $this->eventsBySubject($match->events, $match->home_team_id);
        $awayEventGroups = $this->eventsBySubject($match->events, $match->away_team_id);

        // Same "does THIS sanction keep its subject out of THIS match" check
        // the admin edit page's "Jugadores no disponibles" panel uses --
        // only meaningful before the match is played, but harmless to show
        // either way.
        $homeUnavailable = $this->unavailableSanctions($match, $match->homeTeam);
        $awayUnavailable = $this->unavailableSanctions($match, $match->awayTeam);

        // Sanctions this specific match originated (a card shown here led to
        // a suspension) -- distinct from $home/awayUnavailable above, which
        // is about sanctions from EARLIER matches still blocking this one.
        $originatedSanctions = Sanction::query()
            ->where('match_id', $match->id)
            ->with(['player', 'coach', 'team'])
            ->get();

        return view('pages.public.matches.show', compact(
            'tournament', 'match', 'homeEventGroups', 'awayEventGroups',
            'homeUnavailable', 'awayUnavailable', 'originatedSanctions'
        ));
    }

    /**
     * Groups one side's events by subject (player or coach) -- one group per
     * person, same accumulation x-ui.match-event-row already renders on the
     * admin edit page ("2x Gol, 1x Amarilla" instead of one row per record).
     *
     * @return Collection<int, Collection<int, \App\Models\MatchEvent>>
     */
    private function eventsBySubject(Collection $events, ?int $teamId): Collection
    {
        return $events->where('team_id', $teamId)
            ->groupBy(fn ($event) => $event->player_id !== null ? 'player-'.$event->player_id : 'coach-'.$event->coach_id)
            ->values();
    }

    /**
     * @return EloquentCollection<int, Sanction>
     */
    private function unavailableSanctions(TournamentMatch $match, ?Team $team): EloquentCollection
    {
        if ($team === null) {
            return new EloquentCollection;
        }

        return Sanction::query()
            ->where('team_id', $team->id)
            ->with(['player', 'coach'])
            ->latest('id')
            ->get()
            ->filter(fn (Sanction $sanction): bool => $sanction->blocksMatch($match->id))
            ->values();
    }
}
