<?php

namespace App\Http\Controllers;

use App\Enums\CompetitionPhaseType;
use App\Enums\MatchEventType;
use App\Http\Requests\TournamentMatchRequest;
use App\Models\Sanction;
use App\Models\Team;
use App\Models\TournamentMatch;
use App\Services\KnockoutBracketService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class TournamentMatchController extends Controller
{
    public function edit(TournamentMatch $match): View
    {
        $this->authorize('update', $match);

        $match->load([
            'homeTeam.players' => fn ($query) => $query->orderBy('jersey_number'),
            'homeTeam.coach',
            'awayTeam.players' => fn ($query) => $query->orderBy('jersey_number'),
            'awayTeam.coach',
            // Ordered by registration order, not minute -- minute isn't
            // collected right now (see MatchEventRequest), so it's null for
            // most events and wouldn't produce a meaningful chronology.
            'events' => fn ($query) => $query->with(['player', 'coach'])->orderBy('id'),
            // Only populated for the second leg of a two-legged knockout
            // cross -- the edit page reads it to show the aggregate context.
            'firstLeg',
        ]);

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
            'oldQueuedEvents', 'referees', 'homeUnavailableSanctions', 'awayUnavailableSanctions'
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

        $players = collect($match->homeTeam?->players)->merge($match->awayTeam?->players ?? [])->keyBy('id');
        $coaches = collect([$match->homeTeam?->coach, $match->awayTeam?->coach])->filter()->keyBy('id');
        $validTypes = array_column(MatchEventType::cases(), 'value');

        $grouped = collect($oldEvents)
            ->map(function (array $row) use ($players, $coaches, $validTypes): ?array {
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
                    'teamId' => $subject->team_id,
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

    public function destroy(TournamentMatch $match): RedirectResponse
    {
        $this->authorize('delete', $match);

        $phase = $match->competitionPhase;

        $match->delete();

        return to_route('phases.show', $phase);
    }
}
