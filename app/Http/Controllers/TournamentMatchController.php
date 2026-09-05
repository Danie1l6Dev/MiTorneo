<?php

namespace App\Http\Controllers;

use App\Enums\CompetitionPhaseType;
use App\Enums\MatchEventType;
use App\Http\Requests\TournamentMatchRequest;
use App\Models\TournamentMatch;
use App\Services\KnockoutBracketService;
use Illuminate\Http\RedirectResponse;
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

        return view('pages.matches.edit', compact(
            'match', 'goalCounts', 'playerYellowCounts', 'coachYellowCounts', 'redPlayerIds', 'redCoachIds', 'oldQueuedEvents'
        ));
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
