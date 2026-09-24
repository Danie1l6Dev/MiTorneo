<?php

namespace App\Http\Requests\Concerns;

use App\Enums\MatchEventType;
use App\Models\Coach;
use App\Models\MatchEvent;
use App\Models\Player;
use App\Models\Sanction;
use App\Models\Team;
use App\Models\TournamentMatch;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The rules every batch of quick-add events (the match edit page's "Por
 * guardar" queue) must pass, wherever it's submitted from: the "Guardar
 * eventos" button (MatchEventBatchRequest) or together with the result
 * (MatchResultRequest). Kept in one place so both submits can never drift
 * apart -- each row belongs to one of the match's teams, no event for a
 * suspended player/DT, no goal/assist for a DT, assists never outnumber
 * goals, and the card ceilings (2 yellows, 1 red).
 */
trait ValidatesQueuedMatchEvents
{
    /**
     * @return array<string, mixed>
     */
    protected function queuedEventRules(bool $required): array
    {
        return [
            'events' => $required ? ['required', 'array', 'min:1'] : ['nullable', 'array'],
            'events.*.type' => ['required', Rule::enum(MatchEventType::class)],
            // Each row sends exactly one of player_id/coach_id (the
            // quick-add panels never send both keys for the same row -- see
            // match-roster-panel.blade.php's hidden input name binding).
            // Cross-checking which one, that it's non-empty, that it
            // belongs to one of this match's two teams, and that a coach
            // isn't tied to a goal/assist all happens together in
            // validateQueuedEvents() below, since it needs the whole row at once.
            'events.*.player_id' => ['nullable', 'integer'],
            'events.*.coach_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * $goalLimit is how many goals each side scored in the match (regular +
     * extra time, see TournamentMatch::goalsScored()) -- a team's goal
     * events, and likewise its assist events, saved plus queued, can never
     * add up to more than that. Null
     * while there's no score to hold them to yet (events queued before any
     * result was registered).
     *
     * By default only a team this batch adds goals to is checked, with the
     * error on that team's last queued row. $limitErrorKey switches to
     * checking BOTH teams, reporting on that key instead -- what the result
     * submit needs, since a new, lower score can leave already-saved goals
     * over the limit without any new goal being queued at all.
     *
     * @param  array<int, array{type?: string, player_id?: int|string|null, coach_id?: int|string|null}>  $events
     * @param  array{home: int, away: int}|null  $goalLimit
     */
    protected function validateQueuedEvents(Validator $validator, TournamentMatch $match, array $events, ?array $goalLimit = null, ?string $limitErrorKey = null): void
    {
        $eligibleTeamIds = array_filter([$match->home_team_id, $match->away_team_id]);

        // Tallied across the WHOLE batch (not row by row) since the
        // quick-add flow naturally queues a goal and its assist together
        // in the same submit -- checking row by row would reject the
        // assist for arriving before its own goal within that same
        // request. Keyed by team_id => ['goal' => [...player ids], 'assist' => [...player ids]].
        $newPlayerIdsByTeam = [];
        $lastRelevantIndexByTeam = [];

        // Same idea for cards, but per SUBJECT (not per team) -- keyed
        // by "player:{id}"/"coach:{id}" since player and coach ids share
        // the same numeric range. Covers the "segunda amarilla" queue
        // (one yellow + one red for the same subject in the same batch).
        $newCardCountsBySubject = [];
        $lastCardIndexBySubject = [];

        foreach ($events as $index => $event) {
            $playerId = ! empty($event['player_id']) ? (int) $event['player_id'] : null;
            $coachId = ! empty($event['coach_id']) ? (int) $event['coach_id'] : null;
            $type = $event['type'] ?? null;

            if ($playerId === null && $coachId === null) {
                $validator->errors()->add("events.$index.player_id", __('Cada evento necesita un jugador o un director técnico.'));

                continue;
            }

            if ($playerId !== null && $coachId !== null) {
                $validator->errors()->add("events.$index.player_id", __('Un evento no puede pertenecer a un jugador y a un director técnico a la vez.'));

                continue;
            }

            if ($coachId !== null) {
                if (in_array($type, ['goal', 'assist'], true)) {
                    $validator->errors()->add("events.$index.type", __('Un director técnico no puede anotar goles ni dar asistencias.'));

                    continue;
                }

                if (! Coach::query()->where('id', $coachId)->whereIn('team_id', $eligibleTeamIds)->exists()) {
                    $validator->errors()->add("events.$index.coach_id", __('El director técnico no pertenece a ninguno de los dos equipos de este partido.'));

                    continue;
                }

                // A coach still serving a sanction from an earlier
                // match can't get a new event in a different one -- see
                // MatchEventRequest for the same rule and
                // Sanction::currentlyBlocks() for why the originating
                // match itself is never blocked.
                if (Sanction::currentlyBlocks('coach_id', $coachId, $match->id)) {
                    $validator->errors()->add("events.$index.coach_id", __('Este director técnico tiene una sanción vigente y no puede registrar eventos en este partido.'));

                    continue;
                }

                if (in_array($type, ['yellow_card', 'red_card'], true)) {
                    $key = "coach:$coachId";
                    $newCardCountsBySubject[$key][$type] = ($newCardCountsBySubject[$key][$type] ?? 0) + 1;
                    $lastCardIndexBySubject[$key] = $index;
                }

                continue;
            }

            // A player belongs to this match when they're eligible for
            // either side -- their own roster, or a play-up candidate
            // from a younger category of the same club. See
            // TournamentMatch::eligibleTeamIdForPlayer().
            $player = Player::find($playerId);
            $matchTeamId = $player !== null ? $match->eligibleTeamIdForPlayer($player) : null;

            if ($player === null || $matchTeamId === null) {
                $validator->errors()->add("events.$index.player_id", __('Uno de los jugadores seleccionados no pertenece a ninguno de los dos equipos de este partido.'));

                continue;
            }

            if (Sanction::currentlyBlocks('player_id', $player->id, $match->id)) {
                $validator->errors()->add("events.$index.player_id", __('Este jugador tiene una sanción vigente y no puede registrar eventos en este partido.'));

                continue;
            }

            if (in_array($type, ['goal', 'assist'], true)) {
                $newPlayerIdsByTeam[$matchTeamId][$type][] = $player->id;
                $lastRelevantIndexByTeam[$matchTeamId] = $index;
            }

            if (in_array($type, ['yellow_card', 'red_card'], true)) {
                $key = "player:$playerId";
                $newCardCountsBySubject[$key][$type] = ($newCardCountsBySubject[$key][$type] ?? 0) + 1;
                $lastCardIndexBySubject[$key] = $index;
            }
        }

        // Two related rules, both about a team's goals vs. assists
        // across the WHOLE batch plus what's already saved -- checked
        // together per team since either can be tripped by either a new
        // goal or a new assist row.
        foreach ($lastRelevantIndexByTeam as $teamId => $index) {
            $goalPlayerIds = MatchEvent::query()->where('match_id', $match->id)->where('team_id', $teamId)->where('type', MatchEventType::Goal)->pluck('player_id')->all();
            $assistPlayerIds = MatchEvent::query()->where('match_id', $match->id)->where('team_id', $teamId)->where('type', MatchEventType::Assist)->pluck('player_id')->all();

            $goalPlayerIds = [...$goalPlayerIds, ...($newPlayerIdsByTeam[$teamId]['goal'] ?? [])];
            $assistPlayerIds = [...$assistPlayerIds, ...($newPlayerIdsByTeam[$teamId]['assist'] ?? [])];

            if (count($assistPlayerIds) > count($goalPlayerIds)) {
                // A goal can go unassisted, but an assist always implies
                // a goal -- so a team's assist count can never exceed
                // its goal count.
                $team = Team::find($teamId);

                $validator->errors()->add("events.$index.type", __('No puede haber más asistencias que goles registrados para :team.', ['team' => $team?->name ?? '']));
            } elseif (MatchEvent::someAssisterOutpacesTeammateGoals($goalPlayerIds, $assistPlayerIds)) {
                // A player can't assist their own goal, so their
                // assists can only ever cover goals scored by
                // teammates.
                $team = Team::find($teamId);

                $validator->errors()->add("events.$index.type", __('Algún jugador de :team tiene más asistencias que goles anotados por sus compañeros.', ['team' => $team?->name ?? '']));
            }
        }

        if ($goalLimit !== null) {
            foreach (['home' => $match->home_team_id, 'away' => $match->away_team_id] as $side => $teamId) {
                $newGoals = count($newPlayerIdsByTeam[$teamId]['goal'] ?? []);
                $newAssists = count($newPlayerIdsByTeam[$teamId]['assist'] ?? []);

                if ($teamId === null || ($limitErrorKey === null && $newGoals === 0 && $newAssists === 0)) {
                    continue;
                }

                $savedCount = fn (MatchEventType $type): int => MatchEvent::query()->where('match_id', $match->id)->where('team_id', $teamId)->where('type', $type)->count();

                $message = $this->scoreLimitError($teamId, MatchEventType::Goal, $savedCount(MatchEventType::Goal) + $newGoals, $goalLimit[$side])
                    ?? $this->scoreLimitError($teamId, MatchEventType::Assist, $savedCount(MatchEventType::Assist) + $newAssists, $goalLimit[$side]);

                if ($message !== null) {
                    $validator->errors()->add($limitErrorKey ?? "events.{$lastRelevantIndexByTeam[$teamId]}.type", $message);
                }
            }
        }

        // A 2nd yellow or a red already means expelled -- a 3rd yellow
        // or a 2nd one on top of that makes no sense under ANY event
        // order, unlike the goal/assist rules above.
        foreach ($lastCardIndexBySubject as $key => $index) {
            [$subjectType, $subjectId] = explode(':', $key, 2);
            $column = $subjectType === 'coach' ? 'coach_id' : 'player_id';

            $totalYellow = MatchEvent::query()->where('match_id', $match->id)->where($column, $subjectId)->where('type', MatchEventType::YellowCard)->count()
                + ($newCardCountsBySubject[$key]['yellow_card'] ?? 0);

            $totalRed = MatchEvent::query()->where('match_id', $match->id)->where($column, $subjectId)->where('type', MatchEventType::RedCard)->count()
                + ($newCardCountsBySubject[$key]['red_card'] ?? 0);

            if ($totalYellow > 2) {
                $validator->errors()->add("events.$index.type", __('Ya tiene 2 tarjetas amarillas registradas en este partido.'));
            }

            if ($totalRed > 1) {
                $validator->errors()->add("events.$index.type", __('Ya tiene una tarjeta roja registrada en este partido.'));
            }
        }
    }

    /**
     * The error for $teamId ending up with more goal (or assist) events than
     * the goals it actually scored in the match, or null when it's within
     * the limit -- an assist always implies a goal, so neither can outnumber
     * the score. Shared with MatchEventRequest (a single event) so every way
     * of registering one words it the same.
     */
    protected function scoreLimitError(int $teamId, MatchEventType $type, int $total, int $scoredGoals): ?string
    {
        if ($total <= $scoredGoals) {
            return null;
        }

        return __(':team quedaría con :count :noun registrados, pero en el marcador anotó :score goles. Revisa los eventos o el resultado.', [
            'team' => Team::find($teamId)?->name ?? '',
            'count' => $total,
            'noun' => $type === MatchEventType::Goal ? __('goles') : __('asistencias'),
            'score' => $scoredGoals,
        ]);
    }
}
