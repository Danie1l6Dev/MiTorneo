<?php

namespace App\Http\Requests;

use App\Enums\MatchStatus;
use App\Models\CompetitionPhase;
use App\Models\TournamentMatch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TournamentMatchRequest extends FormRequest
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
        $match = $this->route('match');

        // Editing an existing match: the group, teams and round number are
        // fixed at creation time and can't be changed here, only the status
        // and (optionally) the scheduled date/time.
        if ($match instanceof TournamentMatch) {
            return [
                'status' => ['required', Rule::enum(MatchStatus::class)],
                'scheduled_at' => ['nullable', 'date'],
            ];
        }

        $phase = $this->route('phase');
        $categoryId = $phase instanceof CompetitionPhase ? $phase->category_id : null;

        return [
            'group_id' => [
                'nullable',
                Rule::exists('groups', 'id')->where('category_id', $categoryId),
            ],
            'home_team_id' => [
                'required',
                'different:away_team_id',
                Rule::exists('teams', 'id')->where('category_id', $categoryId),
            ],
            'away_team_id' => [
                'required',
                Rule::exists('teams', 'id')->where('category_id', $categoryId),
            ],
            'status' => ['required', Rule::enum(MatchStatus::class)],
            'round_number' => ['nullable', 'integer', 'min:1'],
            'scheduled_at' => ['nullable', 'date'],
        ];
    }
}
