<?php

namespace App\Http\Requests;

use App\Models\Player;
use App\Models\Team;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PlayerRequest extends FormRequest
{
    /**
     * The only knob to turn if the tournament's numbering rules ever need a
     * wider (or narrower) range -- nothing else about jersey numbers assumes
     * a specific ceiling.
     */
    public const MAX_JERSEY_NUMBER = 99;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $player = $this->route('player');
        $routeTeam = $this->route('team');

        $team = match (true) {
            $routeTeam instanceof Team => $routeTeam,
            $player instanceof Player => $player->team,
            default => null,
        };

        $teamId = $team?->id;

        return [
            'full_name' => ['required', 'string', 'max:255'],
            'document_number' => [
                'required',
                'string',
                'max:30',
                Rule::unique('players', 'document_number')
                    ->where('team_id', $teamId)
                    ->where('is_active', true)
                    ->ignore($player),
            ],
            'jersey_number' => [
                'required',
                'integer',
                'min:1',
                'max:'.self::MAX_JERSEY_NUMBER,
                Rule::unique('players', 'jersey_number')
                    ->where('team_id', $teamId)
                    ->where('is_active', true)
                    ->ignore($player),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'jersey_number.unique' => __('Ya hay un jugador activo de este equipo con ese dorsal.'),
            'document_number.unique' => __('Ya hay un jugador activo de este equipo con ese documento.'),
        ];
    }
}
