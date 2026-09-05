<?php

namespace App\Http\Requests;

use App\Enums\MatchEventType;
use App\Models\Coach;
use App\Models\MatchEvent;
use App\Models\Player;
use App\Models\Team;
use App\Models\TournamentMatch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class MatchEventBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'events' => ['required', 'array', 'min:1'],
            'events.*.type' => ['required', Rule::enum(MatchEventType::class)],
            // Each row sends exactly one of player_id/coach_id (the
            // quick-add panels never send both keys for the same row -- see
            // match-roster-panel.blade.php's hidden input name binding).
            // Cross-checking which one, that it's non-empty, that it
            // belongs to one of this match's two teams, and that a coach
            // isn't tied to a goal/assist all happens together in
            // withValidator() below, since it needs the whole row at once.
            'events.*.player_id' => ['nullable', 'integer'],
            'events.*.coach_id' => ['nullable', 'integer'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $match = $this->route('match');

            if (! $match instanceof TournamentMatch) {
                return;
            }

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

            foreach ((array) $this->input('events', []) as $index => $event) {
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

                    if (in_array($type, ['yellow_card', 'red_card'], true)) {
                        $key = "coach:$coachId";
                        $newCardCountsBySubject[$key][$type] = ($newCardCountsBySubject[$key][$type] ?? 0) + 1;
                        $lastCardIndexBySubject[$key] = $index;
                    }

                    continue;
                }

                $player = Player::query()->where('id', $playerId)->whereIn('team_id', $eligibleTeamIds)->first();

                if ($player === null) {
                    $validator->errors()->add("events.$index.player_id", __('Uno de los jugadores seleccionados no pertenece a ninguno de los dos equipos de este partido.'));

                    continue;
                }

                if (in_array($type, ['goal', 'assist'], true)) {
                    $newPlayerIdsByTeam[$player->team_id][$type][] = $player->id;
                    $lastRelevantIndexByTeam[$player->team_id] = $index;
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
                } elseif (count($goalPlayerIds) === 1 && count($assistPlayerIds) === 1 && $goalPlayerIds[0] === $assistPlayerIds[0]) {
                    // With no per-goal link between a goal and its assist,
                    // this is only ever certain in the trivial
                    // 1-goal-1-assist case: that single assist can only be
                    // for that single goal, so it can't also be the scorer.
                    $team = Team::find($teamId);

                    $validator->errors()->add("events.$index.type", __('El único gol y la única asistencia de :team no pueden ser del mismo jugador.', ['team' => $team?->name ?? '']));
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
        });
    }
}
