<?php

namespace App\Http\Requests;

use App\Models\Team;
use App\Models\TournamentMatch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class MatchLineupRequest extends FormRequest
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
        /** @var TournamentMatch|null $match */
        $match = $this->route('match');

        $eligibleTeamIds = array_filter([$match?->home_team_id, $match?->away_team_id]);

        return [
            'team_id' => ['required', Rule::in($eligibleTeamIds)],
            'player_ids' => ['required', 'array', 'min:1'],
            'player_ids.*' => ['integer'],
        ];
    }

    /**
     * Re-checked here instead of trusted from the search panel's checkboxes
     * -- exactly the same candidate list Team::clubPlayersEligibleForLineup()
     * builds for that panel is what a submitted player_id is checked
     * against, so nothing outside a club's own roster (or the play-up
     * age-eligibility rule) can ever be called up.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var TournamentMatch|null $match */
            $match = $this->route('match');
            $team = Team::find($this->input('team_id'));

            if ($match === null || $team === null) {
                return;
            }

            $eligibleIds = $team->clubPlayersEligibleForLineup()->pluck('id')->all();
            $submittedIds = array_map('intval', (array) $this->input('player_ids', []));
            $invalidIds = array_diff($submittedIds, $eligibleIds);

            if ($invalidIds !== []) {
                $validator->errors()->add('player_ids', __('Alguno de los jugadores seleccionados no puede jugar en este equipo.'));
            }
        });
    }
}
