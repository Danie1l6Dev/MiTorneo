<?php

namespace App\Http\Requests;

use App\Enums\MatchStatus;
use App\Models\TournamentMatch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class MatchResultRequest extends FormRequest
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
        $rules = [
            'home_score' => ['required', 'integer', 'min:0'],
            'away_score' => ['required', 'integer', 'min:0'],
        ];

        if ($this->isDecisiveLeg()) {
            $rules['home_extra_time_score'] = ['nullable', 'integer', 'min:0'];
            $rules['away_extra_time_score'] = ['nullable', 'integer', 'min:0'];
            $rules['home_penalty_score'] = ['nullable', 'integer', 'min:0'];
            $rules['away_penalty_score'] = ['nullable', 'integer', 'min:0'];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('home_score') || $validator->errors()->has('away_score')) {
                return;
            }

            $match = $this->route('match');

            if (! $match instanceof TournamentMatch) {
                return;
            }

            if ($match->home_team_id === null || $match->away_team_id === null) {
                $validator->errors()->add('home_score', __('Todavía no se conocen los dos equipos de este partido; espera a que se definan los clasificados de la ronda anterior.'));

                return;
            }

            // The decisive (second) leg of a two-legged cross can't be
            // scored before its first leg is: the aggregate it must be
            // validated against doesn't exist yet.
            if ($match->first_leg_match_id !== null && $match->firstLeg->status !== MatchStatus::Finished) {
                $validator->errors()->add('home_score', __('Todavía no se registró el resultado de la ida de este cruce; regístralo primero.'));

                return;
            }

            // A league match, or the first leg of a two-legged knockout
            // cross, is never decisive on its own -- any score, including a
            // draw, is valid; the cross itself is only resolved once its
            // decisive leg is scored.
            if (! $this->isDecisiveLeg()) {
                return;
            }

            $homeExtraTime = $this->filled('home_extra_time_score') ? (int) $this->input('home_extra_time_score') : null;
            $awayExtraTime = $this->filled('away_extra_time_score') ? (int) $this->input('away_extra_time_score') : null;
            $homePenalties = $this->filled('home_penalty_score') ? (int) $this->input('home_penalty_score') : null;
            $awayPenalties = $this->filled('away_penalty_score') ? (int) $this->input('away_penalty_score') : null;

            if (($homeExtraTime === null) !== ($awayExtraTime === null)) {
                $validator->errors()->add('home_extra_time_score', __('Indica el resultado de la prórroga para ambos equipos.'));

                return;
            }

            if (($homePenalties === null) !== ($awayPenalties === null)) {
                $validator->errors()->add('home_penalty_score', __('Indica el resultado de los penales para ambos equipos.'));

                return;
            }

            // For a single-match cross this is just its own score; for the
            // decisive leg of a two-legged cross it's aggregated with the
            // (already-finished) first leg, mapped onto this leg's sides --
            // the same swap TournamentMatch::regularTimeAggregate() applies.
            $isSecondLeg = $match->first_leg_match_id !== null;
            $homeRegular = (int) $this->input('home_score') + ($isSecondLeg ? $match->firstLeg->away_score : 0);
            $awayRegular = (int) $this->input('away_score') + ($isSecondLeg ? $match->firstLeg->home_score : 0);

            $homeTotal = $homeRegular + ($homeExtraTime ?? 0);
            $awayTotal = $awayRegular + ($awayExtraTime ?? 0);

            if ($homeTotal !== $awayTotal) {
                return;
            }

            if ($homePenalties === null || $awayPenalties === null) {
                $validator->errors()->add('home_score', $isSecondLeg
                    ? __('En una eliminatoria de ida y vuelta el resultado global no puede terminar empatado; añade una prórroga o los penales para definir un ganador.')
                    : __('En una fase eliminatoria el partido no puede terminar empatado; añade una prórroga o los penales para definir un ganador.'));

                return;
            }

            if ($homePenalties === $awayPenalties) {
                $validator->errors()->add('home_penalty_score', __('Los penales no pueden terminar empatados; debe haber un ganador.'));
            }
        });
    }

    /**
     * Whether this match's own result is what decides who advances out of
     * its knockout cross -- false for a league match or the first leg of a
     * two-legged cross still awaiting its second.
     */
    private function isDecisiveLeg(): bool
    {
        $match = $this->route('match');

        return $match instanceof TournamentMatch && $match->isDecisiveKnockoutLeg();
    }
}
