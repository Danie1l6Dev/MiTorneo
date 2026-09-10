<?php

namespace App\Http\Requests;

use App\Enums\MatchStatus;
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
        return [
            'status' => ['required', Rule::enum(MatchStatus::class)],
            'scheduled_at' => ['nullable', 'date'],
            // Scoped to the authenticated organizer's own referees -- a
            // referee is global, but never across different organizers.
            'referee_id' => ['nullable', Rule::exists('referees', 'id')->where('user_id', $this->user()?->id)],
        ];
    }
}
