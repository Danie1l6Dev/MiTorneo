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
        $phase = $this->route('phase');
        $match = $this->route('match');

        if (! $phase instanceof CompetitionPhase && $match instanceof TournamentMatch) {
            $phase = $match->competitionPhase;
        }

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
            'home_score' => ['nullable', 'integer', 'min:0'],
            'away_score' => ['nullable', 'integer', 'min:0'],
            'status' => ['required', Rule::enum(MatchStatus::class)],
            'round' => ['nullable', 'string', 'max:100'],
            'scheduled_at' => ['nullable', 'date'],
        ];
    }
}
