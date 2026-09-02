<?php

namespace App\Http\Requests;

use App\Enums\CompetitionPhaseType;
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

        if ($this->isKnockoutMatch()) {
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

            if (! $this->isKnockoutMatch()) {
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

            $homeTotal = (int) $this->input('home_score') + ($homeExtraTime ?? 0);
            $awayTotal = (int) $this->input('away_score') + ($awayExtraTime ?? 0);

            if ($homeTotal !== $awayTotal) {
                return;
            }

            if ($homePenalties === null || $awayPenalties === null) {
                $validator->errors()->add('home_score', __('En una fase eliminatoria el partido no puede terminar empatado; añade una prórroga o los penales para definir un ganador.'));

                return;
            }

            if ($homePenalties === $awayPenalties) {
                $validator->errors()->add('home_penalty_score', __('Los penales no pueden terminar empatados; debe haber un ganador.'));
            }
        });
    }

    private function isKnockoutMatch(): bool
    {
        $match = $this->route('match');

        return $match instanceof TournamentMatch && $match->competitionPhase->type !== CompetitionPhaseType::League;
    }
}
