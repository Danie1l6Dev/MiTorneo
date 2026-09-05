<?php

namespace App\Http\Controllers;

use App\Enums\MatchEventType;
use App\Http\Requests\MatchEventBatchRequest;
use App\Http\Requests\MatchEventRequest;
use App\Models\Coach;
use App\Models\MatchEvent;
use App\Models\Player;
use App\Models\TournamentMatch;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class MatchEventController extends Controller
{
    public function create(TournamentMatch $match): View|RedirectResponse
    {
        $this->authorize('create', [MatchEvent::class, $match]);

        if ($redirect = $this->guardKnownTeams($match)) {
            return $redirect;
        }

        return view('pages.matches.events.create', [
            'match' => $match,
            'players' => $this->eligiblePlayers($match),
            'coaches' => $this->eligibleCoaches($match),
        ]);
    }

    public function store(MatchEventRequest $request, TournamentMatch $match): RedirectResponse
    {
        $this->authorize('create', [MatchEvent::class, $match]);

        if ($redirect = $this->guardKnownTeams($match)) {
            return $redirect;
        }

        $minute = $request->validated('minute') !== null ? (int) $request->validated('minute') : null;

        $match->events()->create([
            ...$this->resolveSubject($request->validated('player_id'), $request->validated('coach_id')),
            'type' => $request->validated('type'),
            'minute' => $minute,
        ]);

        return to_route('matches.edit', $match)->with('status', __('Evento registrado correctamente.'));
    }

    /**
     * Registers several events for this match in one request -- what the
     * quick-add roster panels actually submit: every click there only queues
     * a { type, player_id|coach_id } pair client-side (see
     * match-roster-panel.blade.php and matches/edit.blade.php's Alpine
     * state), and nothing is persisted until this single "Guardar eventos"
     * submission. A "segunda amarilla" click is not a special case here
     * either -- the client simply queues an extra independent yellow + red
     * pair, so this always just creates one plain row per queued item.
     */
    public function storeBatch(MatchEventBatchRequest $request, TournamentMatch $match): RedirectResponse
    {
        $this->authorize('create', [MatchEvent::class, $match]);

        if ($redirect = $this->guardKnownTeams($match)) {
            return $redirect;
        }

        $events = collect($request->validated('events'));
        $players = Player::query()->whereIn('id', $events->pluck('player_id')->filter()->unique())->get()->keyBy('id');
        $coaches = Coach::query()->whereIn('id', $events->pluck('coach_id')->filter()->unique())->get()->keyBy('id');

        DB::transaction(function () use ($match, $events, $players, $coaches): void {
            foreach ($events as $eventData) {
                $subject = ! empty($eventData['coach_id'])
                    ? $this->resolveSubject(null, $coaches->get($eventData['coach_id']))
                    : $this->resolveSubject($players->get($eventData['player_id']), null);

                $match->events()->create([
                    ...$subject,
                    'type' => $eventData['type'],
                ]);
            }
        });

        return to_route('matches.edit', $match)->with('status', trans_choice(
            ':count evento registrado correctamente.|:count eventos registrados correctamente.',
            $events->count(),
            ['count' => $events->count()]
        ));
    }

    public function destroy(MatchEvent $event): RedirectResponse
    {
        $this->authorize('delete', $event);

        $match = $event->match;

        if ($event->type === MatchEventType::Goal && $this->deletingWouldLeaveTooManyAssists($event)) {
            return to_route('matches.edit', $match)->with('error', __(
                'No se puede eliminar este gol: :team quedaría con más asistencias que goles registrados. Eliminá primero una asistencia.',
                ['team' => $event->team->name]
            ));
        }

        DB::transaction(function () use ($event): void {
            $this->cascadeCardDeletion($event);
            $event->delete();
        });

        return to_route('matches.edit', $match)->with('status', __('Evento eliminado correctamente.'));
    }

    /**
     * Mirrors the assist-vs-goal creation rule (MatchEventRequest /
     * MatchEventBatchRequest) onto deletion -- removing a goal can just as
     * easily break "assists <= goals" for the team as adding an
     * over-the-limit assist can.
     */
    private function deletingWouldLeaveTooManyAssists(MatchEvent $goalEvent): bool
    {
        $remainingGoals = MatchEvent::query()
            ->where('match_id', $goalEvent->match_id)
            ->where('team_id', $goalEvent->team_id)
            ->where('type', MatchEventType::Goal)
            ->where('id', '!=', $goalEvent->id)
            ->count();

        $assists = MatchEvent::query()
            ->where('match_id', $goalEvent->match_id)
            ->where('team_id', $goalEvent->team_id)
            ->where('type', MatchEventType::Assist)
            ->count();

        return $assists > $remainingGoals;
    }

    /**
     * Undoes the "segunda amarilla" pairing symmetrically: deleting the
     * auto-added red card also reverts one of the two yellows that caused it
     * (back to a single caution, no expulsion on record), and deleting
     * either of those two yellows also removes the red -- since a red's
     * only certain justification (see the card-ceiling validation rule) is
     * "this subject had exactly 2 yellows", which no longer holds once one
     * of them is gone. Whichever of the three rows is deleted first, the
     * other one/two always lands on "1 yellow, 0 red" -- the two yellow rows
     * are interchangeable (nothing distinguishes them), so it never matters
     * WHICH one is removed. A lone yellow coexisting with an unrelated
     * straight red (a perfectly normal, separate combo) is left untouched,
     * since that state never had 2 yellows to begin with.
     */
    private function cascadeCardDeletion(MatchEvent $event): void
    {
        if (! in_array($event->type, [MatchEventType::YellowCard, MatchEventType::RedCard], true)) {
            return;
        }

        $subjectColumn = $event->coach_id !== null ? 'coach_id' : 'player_id';
        $subjectId = $event->coach_id ?? $event->player_id;

        $cardsFor = fn (MatchEventType $type) => MatchEvent::query()
            ->where('match_id', $event->match_id)
            ->where($subjectColumn, $subjectId)
            ->where('type', $type);

        if ($event->type === MatchEventType::RedCard) {
            if ($cardsFor(MatchEventType::YellowCard)->count() === 2) {
                $cardsFor(MatchEventType::YellowCard)->first()?->delete();
            }

            return;
        }

        if ($cardsFor(MatchEventType::YellowCard)->count() === 2) {
            $cardsFor(MatchEventType::RedCard)->first()?->delete();
        }
    }

    private function guardKnownTeams(TournamentMatch $match): ?RedirectResponse
    {
        if ($match->home_team_id === null || $match->away_team_id === null) {
            return to_route('matches.edit', $match)->with('error', __(
                'Todavía no se conocen los dos equipos de este partido.'
            ));
        }

        return null;
    }

    /**
     * Resolves whichever of a player id/model or a coach id/model was given
     * (accepts both raw ids -- from validated request input -- and already
     * -loaded models -- from storeBatch's pre-fetched collections) into the
     * team_id/player_id/coach_id triplet an event row actually stores,
     * always nulling out the side that doesn't apply.
     *
     * @return array{team_id: int, player_id: int|null, coach_id: int|null}
     */
    private function resolveSubject(Player|int|null $player, Coach|int|null $coach): array
    {
        if ($coach !== null) {
            $coach = $coach instanceof Coach ? $coach : Coach::findOrFail($coach);

            return ['team_id' => $coach->team_id, 'player_id' => null, 'coach_id' => $coach->id];
        }

        $player = $player instanceof Player ? $player : Player::findOrFail($player);

        return ['team_id' => $player->team_id, 'player_id' => $player->id, 'coach_id' => null];
    }

    /**
     * @return Collection<int, Player>
     */
    private function eligiblePlayers(TournamentMatch $match): Collection
    {
        return Player::query()
            ->with('team')
            ->whereIn('team_id', array_filter([$match->home_team_id, $match->away_team_id]))
            ->orderBy('jersey_number')
            ->get();
    }

    /**
     * @return Collection<int, Coach>
     */
    private function eligibleCoaches(TournamentMatch $match): Collection
    {
        return Coach::query()
            ->with('team')
            ->whereIn('team_id', array_filter([$match->home_team_id, $match->away_team_id]))
            ->where('is_active', true)
            ->get();
    }
}
