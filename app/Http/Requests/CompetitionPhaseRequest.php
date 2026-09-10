<?php

namespace App\Http\Requests;

use App\Enums\CompetitionPhaseType;
use App\Enums\ScheduleFormat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompetitionPhaseRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(CompetitionPhaseType::class)],
            // Only a knockout-style phase (Knockout/Semifinal/Final) is
            // played as a bracket of crosses, so this only matters for one --
            // it's ignored (and stored as null) for a league phase either
            // way, and defaults to a single match per cross when omitted.
            'knockout_format' => ['nullable', Rule::enum(ScheduleFormat::class)],
            'order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
