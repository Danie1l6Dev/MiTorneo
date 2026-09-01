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
        return [
            'home_score' => ['required', 'integer', 'min:0'],
            'away_score' => ['required', 'integer', 'min:0'],
        ];
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

            $isKnockoutMatch = $match->competitionPhase->type !== CompetitionPhaseType::League;

            if ($isKnockoutMatch && (int) $this->input('home_score') === (int) $this->input('away_score')) {
                $validator->errors()->add('home_score', __('En una fase eliminatoria el partido no puede terminar empatado; define un ganador.'));
            }
        });
    }
}
