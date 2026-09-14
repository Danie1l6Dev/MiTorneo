<?php

namespace App\Http\Controllers;

use App\Enums\CompetitionPhaseType;
use App\Enums\MatchEventType;
use App\Enums\MatchStatus;
use App\Http\Requests\TournamentMatchRequest;
use App\Models\MatchEvent;
use App\Models\Sanction;
use App\Models\Team;
use App\Models\TournamentMatch;
use App\Services\KnockoutBracketService;
use App\Services\SanctionService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class TournamentMatchController extends Controller
{
    public function edit(TournamentMatch $match): View
    {
        $this->authorize('update', $match);

        $match->load([
            'homeTeam.coach',
            'awayTeam.coach',
            // Who's actually called up to play this match -- what the
            // quick-add roster panels are now built from instead of each
            // team's full category plantel. See MatchLineup's docblock.
            'lineups.player',
            // Ordered by registration order, not minute -- minute isn't
            // collected right now (see MatchEventRequest), so it's null for
            // most events and wouldn't produce a meaningful chronology.
            'events' => fn ($query) => $query->with(['player', 'coach'])->orderBy('id'),
            // Only populated for the second leg of a two-legged knockout
            // cross -- the edit page reads it to show the aggregate context.
            'firstLeg',
        ]);

        $homeLineups = $match->lineups->where('team_id', $match->home_team_id)->values();
        $awayLineups = $match->lineups->where('team_id', $match->away_team_id)->values();

        // The club-wide search candidates for each side's "agregar
        // convocados" panel -- everything Team::clubPlayersEligibleForLineup()
        // considers eligible (this team's own roster, plus play-up-eligible
        // players from the rest of the club) minus whoever's already called
        // up. Suspended players are filtered out client-side in the view,
        // alongside the same $home/awayUnavailablePlayerIds the roster panel
        // itself uses, since both lists are only known once
        // unavailableSanctions() below has run.
        $homeEligiblePlayers = $match->homeTeam?->clubPlayersEligibleForLineup() ?? collect();
        $awayEligiblePlayers = $match->awayTeam?->clubPlayersEligibleForLineup() ?? collect();

        $homeCandidates = $homeEligiblePlayers->whereNotIn('id', $homeLineups->pluck('player_id'))->values();
        $awayCandidates = $awayEligiblePlayers->whereNotIn('id', $awayLineups->pluck('player_id'))->values();

        // Lets the search panel tell "everyone eligible is already
        // convocado" (nothing to do) apart from "this club has no players
        // to search at all" (needs a "ve a cargarlos" pointer instead) --
        // both look identical from $homeCandidates/$awayCandidates alone
        // once it's empty.
        $homeClubHasEligiblePlayers = $homeEligiblePlayers->isNotEmpty();
        $awayClubHasEligiblePlayers = $awayEligiblePlayers->isNotEmpty();

        // Purely informational -- the scoreboard stays the source of truth
        // for the result/standings/bracket, this only flags the goal events
        // logged so far disagreeing with it, without blocking anything.
        $goalCounts = [
            'home' => $match->events->where('team_id', $match->home_team_id)->where('type', MatchEventType::Goal)->count(),
            'away' => $match->events->where('team_id', $match->away_team_id)->where('type', MatchEventType::Goal)->count(),
        ];

        // Seed the quick-add roster/DT panels' client-side "click amarilla
        // twice = expulsión" logic with what's already saved for this match,
        // so a player or coach who already has a yellow from an earlier save
        // is correctly treated as one click away from their second. Player
        // and coach counts are kept in separate maps since their ids share
        // the same numeric range across tables.
        $playerYellowCounts = $match->events->where('type', MatchEventType::YellowCard)->whereNotNull('player_id')->countBy('player_id');
        $coachYellowCounts = $match->events->where('type', MatchEventType::YellowCard)->whereNotNull('coach_id')->countBy('coach_id');
        $redPlayerIds = $match->events->where('type', MatchEventType::RedCard)->pluck('player_id')->filter()->unique()->values();
        $redCoachIds = $match->events->where('type', MatchEventType::RedCard)->pluck('coach_id')->filter()->unique()->values();

        // Rebuilds the quick-add "Guardar eventos" queue from `old('events')`
        // after a failed batch submit (e.g. the assist-vs-goal rule), so
        // whatever was already queued survives the redirect instead of the
        // user having to re-click every icon -- they only need to fix
        // whichever entry actually got rejected.
        $oldQueuedEvents = $this->reconstructQueuedEvents($match, (array) old('events', []));

        $referees = Auth::user()->referees()->orderBy('full_name')->get();

        // A player/coach still serving a sanction (from ANY earlier match,
        // any phase -- suspensions follow the person across the whole
        // tournament, not just the phase they were carded in) shouldn't be
        // offered goal/assist/card buttons for a DIFFERENT match. The match
        // where the card itself was shown is excluded on purpose: they
        // played that one, the suspension only affects the ones after it.
        $homeUnavailableSanctions = $this->unavailableSanctions($match, $match->homeTeam);
        $awayUnavailableSanctions = $this->unavailableSanctions($match, $match->awayTeam);

        return view('pages.matches.edit', compact(
            'match', 'goalCounts', 'playerYellowCounts', 'coachYellowCounts', 'redPlayerIds', 'redCoachIds',
            'oldQueuedEvents', 'referees', 'homeUnavailableSanctions', 'awayUnavailableSanctions',
            'homeLineups', 'awayLineups', 'homeCandidates', 'awayCandidates',
            'homeClubHasEligiblePlayers', 'awayClubHasEligiblePlayers'
        ));
    }

    /**
     * @return Collection<int, Sanction>
     */
    private function unavailableSanctions(TournamentMatch $match, ?Team $team): Collection
    {
        if ($team === null) {
            return new Collection;
        }

        // Sanction::blocksMatch() is what actually decides "does this
        // specific sanction keep its subject out of THIS match" -- it
        // accounts for the team's real match order (so a fixture from
        // BEFORE the sanction was ever handed out is never flagged) and,
        // once resolved, for exactly how many of the following matches the
        // fechas cover (so nothing needs to be marked "served" manually).
        return Sanction::query()
            ->where('team_id', $team->id)
            ->with(['player', 'coach'])
            ->latest('id')
            ->get()
            ->filter(fn (Sanction $sanction): bool => $sanction->blocksMatch($match->id))
            ->values();
    }

    /**
     * @param  array<int, array{type?: string, player_id?: int|string|null, coach_id?: int|string|null}>  $oldEvents
     * @return array<int, array{uid: int, type: string, subjectType: string, subjectId: int, teamId: int, label: string, note: null, count: int}>
     */
    private function reconstructQueuedEvents(TournamentMatch $match, array $oldEvents): array
    {
        if ($oldEvents === []) {
            return [];
        }

        // Same "belongs directly OR via the lineup" set MatchEventRequest/
        // MatchEventBatchRequest validate against -- a queued event can be
        // for a player called up through the new search panel (in
        // $match->lineups) just as much as one created directly on the
        // team (the pre-lineup-feature path some flows still use). See
        // TournamentMatch::lineupTeamIdFor() for why teamId below can't
        // just be $subject->team_id: a player called up to play UP from a
        // younger category's roster has that pointing at their own team,
        // not this match's side.
        $players = collect($match->homeTeam?->players)
            ->merge($match->awayTeam?->players ?? [])
            ->merge($match->lineups->pluck('player'))
            ->filter()
            ->keyBy('id');
        $coaches = collect([$match->homeTeam?->coach, $match->awayTeam?->coach])->filter()->keyBy('id');
        $validTypes = array_column(MatchEventType::cases(), 'value');

        $grouped = collect($oldEvents)
            ->map(function (array $row) use ($players, $coaches, $validTypes, $match): ?array {
                $type = $row['type'] ?? null;
                $isCoach = ! empty($row['coach_id']);
                $subjectId = (int) ($isCoach ? $row['coach_id'] : ($row['player_id'] ?? 0));
                $subject = $isCoach ? $coaches->get($subjectId) : $players->get($subjectId);

                if (! in_array($type, $validTypes, true) || $subject === null) {
                    return null;
                }

                return [
                    'type' => $type,
                    'subjectType' => $isCoach ? 'coach' : 'player',
                    'subjectId' => $subjectId,
                    'teamId' => $isCoach ? $subject->team_id : $match->lineupTeamIdFor($subject),
                    'label' => $isCoach ? __('DT').': '.$subject->full_name : $subject->full_name,
                ];
            })
            ->filter()
            ->groupBy(fn (array $row): string => $row['type'].'|'.$row['subjectType'].'|'.$row['subjectId']);

        return $grouped->values()->map(function ($group, $index): array {
            $item = $group->first();
            $item['uid'] = $index + 1;
            $item['note'] = null;
            $item['count'] = $group->count();

            return $item;
        })->values()->all();
    }

    public function update(TournamentMatchRequest $request, TournamentMatch $match, KnockoutBracketService $bracketService): RedirectResponse
    {
        $this->authorize('update', $match);

        $match->update($request->validated());

        if ($match->home_score !== null && $match->away_score !== null) {
            $bracketService->resolveWinner($match);
        }

        $isKnockoutMatch = $match->competitionPhase->type !== CompetitionPhaseType::League;

        return redirect(route('phases.show', $match->competitionPhase).($isKnockoutMatch ? '#cuadro' : ''))
            ->with('status', __('Cambios guardados correctamente.'));
    }

    /**
     * Undoes a registered result entirely: score (regular/extra time/
     * penalties), status back to Scheduled, and every match_event this
     * match has -- not just the scoreboard, since an organizer who wants to
     * redo a match usually got the events wrong too, not just the numbers.
     * The lineup (match_lineups) is left untouched -- who was called up to
     * play is independent of what happened once they did.
     *
     * Blocked the same way a single card's deletion already is
     * (MatchEventController) when any event here backs a Sanction the
     * Comité Directivo already resolved -- sanctions.match_event_id
     * cascades on delete, so silently wiping the events would silently
     * destroy that administrative record too.
     *
     * Deliberately doesn't try to unwind any knockout bracket propagation
     * this match's result may have already triggered (KnockoutBracketService)
     * -- the same gap already exists for deleting the match outright.
     */
    public function reset(TournamentMatch $match, SanctionService $sanctions): RedirectResponse
    {
        $this->authorize('update', $match);

        $protectedEvent = $match->events->first(fn (MatchEvent $event): bool => $sanctions->protectedSanctionFor($event) !== null);

        if ($protectedEvent !== null) {
            return to_route('matches.edit', $match)->with('error', __(
                'No se puede resetear este partido: :subject tiene una sanción ya resuelta por el Comité Directivo originada aquí. Resuélvela o elimínala primero.',
                ['subject' => $protectedEvent->subjectLabel()]
            ));
        }

        DB::transaction(function () use ($match): void {
            $match->events()->delete();

            $match->update([
                'home_score' => null,
                'away_score' => null,
                'home_extra_time_score' => null,
                'away_extra_time_score' => null,
                'home_penalty_score' => null,
                'away_penalty_score' => null,
                'status' => MatchStatus::Scheduled,
            ]);
        });

        return to_route('matches.edit', $match)->with('status', __('Partido reseteado: resultado y eventos eliminados.'));
    }

    public function destroy(TournamentMatch $match): RedirectResponse
    {
        $this->authorize('delete', $match);

        $phase = $match->competitionPhase;

        $match->delete();

        return to_route('phases.show', $phase);
    }
}
