<?php

namespace App\Http\Requests;

use App\Enums\MatchEventType;
use App\Models\MatchEvent;
use App\Models\Player;
use App\Models\TournamentMatch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class MatchEventRequest extends FormRequest
{
    /**
     * The only knob to turn if a future competition needs to record beyond
     * regulation + extra time; nothing else assumes this ceiling.
     */
    public const MAX_MINUTE = 130;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * The dedicated create form offers one combined <select> ("Jugador" or
     * "DT") instead of two dependent ones, using a composite
     * "player:{id}"/"coach:{id}" option value -- this splits that back into
     * the plain player_id/coach_id the rest of validation and the controller
     * expect. The quick-add roster panels never send `subject`; they already
     * submit player_id/coach_id directly, so this is a no-op for that flow.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->filled('subject') || $this->filled('player_id') || $this->filled('coach_id')) {
            return;
        }

        [$type, $id] = array_pad(explode(':', (string) $this->input('subject'), 2), 2, null);

        $this->merge($type === 'coach' ? ['coach_id' => $id] : ['player_id' => $id]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $match = $this->route('match');

        $eligibleTeamIds = array_filter([$match?->home_team_id, $match?->away_team_id]);

        return [
            'type' => ['required', Rule::enum(MatchEventType::class)],
            // Exactly one of these two is ever valid -- both "required
            // unless the other is present" and mutual exclusivity are
            // enforced together in withValidator() below, since Laravel's
            // required_without doesn't also forbid both being sent at once.
            'player_id' => [
                'nullable',
                Rule::exists('players', 'id')->where(fn ($query) => $query->whereIn('team_id', $eligibleTeamIds)),
            ],
            'coach_id' => [
                'nullable',
                Rule::exists('coaches', 'id')->where(fn ($query) => $query->whereIn('team_id', $eligibleTeamIds)),
            ],
            // Not required: minute tracking isn't used yet -- an event only
            // needs to count toward stats (goals/assists/cards), not be tied
            // to a moment in the match. Still validated when given, since a
            // future phase may start asking for it.
            'minute' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_MINUTE],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var TournamentMatch|null $match */
            $match = $this->route('match');
            $hasPlayer = $this->filled('player_id');
            $hasCoach = $this->filled('coach_id');

            if (! $hasPlayer && ! $hasCoach) {
                $validator->errors()->add('player_id', __('Selecciona un jugador o un director técnico.'));

                return;
            }

            if ($hasPlayer && $hasCoach) {
                $validator->errors()->add('player_id', __('Un evento no puede pertenecer a un jugador y a un director técnico a la vez.'));

                return;
            }

            if ($hasCoach && in_array($this->input('type'), ['goal', 'assist'], true)) {
                $validator->errors()->add('type', __('Un director técnico no puede anotar goles ni dar asistencias.'));

                return;
            }

            if ($match === null) {
                return;
            }

            // Two related rules, both about a team's goals vs. assists in
            // this match -- checked together since adding either a goal or
            // an assist can trip either one.
            if ($hasPlayer && in_array($this->input('type'), [MatchEventType::Goal->value, MatchEventType::Assist->value], true)) {
                $player = Player::find($this->input('player_id'));

                if ($player !== null) {
                    $playerIdsFor = fn (MatchEventType $type) => MatchEvent::query()
                        ->where('match_id', $match->id)
                        ->where('team_id', $player->team_id)
                        ->where('type', $type)
                        ->pluck('player_id')
                        ->all();

                    $goalPlayerIds = $playerIdsFor(MatchEventType::Goal);
                    $assistPlayerIds = $playerIdsFor(MatchEventType::Assist);

                    if ($this->input('type') === MatchEventType::Goal->value) {
                        $goalPlayerIds[] = $player->id;
                    } else {
                        $assistPlayerIds[] = $player->id;
                    }

                    if (count($assistPlayerIds) > count($goalPlayerIds)) {
                        // A goal can go unassisted, but an assist always
                        // implies a goal -- so a team's assist count can
                        // never exceed its goal count.
                        $validator->errors()->add('type', __('No puede haber más asistencias que goles registrados para :team.', ['team' => $player->team->name]));
                    } elseif (count($goalPlayerIds) === 1 && count($assistPlayerIds) === 1 && $goalPlayerIds[0] === $assistPlayerIds[0]) {
                        // With no per-goal link between a goal and its
                        // assist, this is only ever certain in the trivial
                        // 1-goal-1-assist case: that single assist can only
                        // be for that single goal, so it can't also be the
                        // scorer.
                        $validator->errors()->add('type', __('El único gol y la única asistencia de :team no pueden ser del mismo jugador.', ['team' => $player->team->name]));
                    }
                }
            }

            // A 2nd yellow or a red already means expelled -- a 3rd yellow
            // or a 2nd one on top of that makes no sense under ANY event
            // order, unlike the goal/assist rules above (which depend on
            // knowing what happened before what, and minute isn't tracked).
            // Applies to a coach exactly the same as a player.
            if (in_array($this->input('type'), [MatchEventType::YellowCard->value, MatchEventType::RedCard->value], true)) {
                $subjectColumn = $hasCoach ? 'coach_id' : 'player_id';
                $subjectId = $this->input($subjectColumn);

                $cardCountFor = fn (MatchEventType $type) => MatchEvent::query()
                    ->where('match_id', $match->id)
                    ->where($subjectColumn, $subjectId)
                    ->where('type', $type)
                    ->count();

                if ($this->input('type') === MatchEventType::YellowCard->value && $cardCountFor(MatchEventType::YellowCard) >= 2) {
                    $validator->errors()->add('type', __('Ya tiene 2 tarjetas amarillas registradas en este partido.'));
                }

                if ($this->input('type') === MatchEventType::RedCard->value && $cardCountFor(MatchEventType::RedCard) >= 1) {
                    $validator->errors()->add('type', __('Ya tiene una tarjeta roja registrada en este partido.'));
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'player_id.exists' => __('El jugador seleccionado no pertenece a ninguno de los dos equipos de este partido.'),
            'coach_id.exists' => __('El director técnico seleccionado no pertenece a ninguno de los dos equipos de este partido.'),
        ];
    }
}
