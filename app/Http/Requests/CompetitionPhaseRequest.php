<?php

namespace App\Http\Requests;

use App\Enums\CompetitionPhaseType;
use App\Enums\ScheduleFormat;
use App\Models\Category;
use App\Models\Tournament;
use App\Services\PhaseEligibilityService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            // Both ignored (and stored as null/false) for a league phase.
            // plays_third_place is further gated in withValidator() below on
            // there being at least 4 eligible teams -- see
            // PhaseEligibilityService::canPlayThirdPlace().
            'plays_third_place' => ['sometimes', 'boolean'],
            'final_knockout_format' => ['nullable', Rule::enum(ScheduleFormat::class)],
            'order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $type = CompetitionPhaseType::tryFrom((string) $this->input('type'));

            if ($type !== CompetitionPhaseType::Knockout || ! $this->boolean('plays_third_place')) {
                return;
            }

            // A category's first phase is always reached either through
            // {category} (resolves its sole tournament) or through
            // {tournament}/{category} directly -- same dual route shape
            // CompetitionPhaseController itself handles.
            $category = $this->route('category');

            if (! $category instanceof Category) {
                return;
            }

            // {tournament} is only present on the *ForTournament route
            // variant; the plain {category} route resolves its own sole
            // tournament, same as CompetitionPhaseController itself does.
            $tournament = $this->route('tournament') instanceof Tournament
                ? $this->route('tournament')
                : $category->resolveSoleTournament();

            $eligibilityService = app(PhaseEligibilityService::class);
            $teamCount = $eligibilityService->eligibleTeams($category, $tournament)->count();

            if (! $eligibilityService->canPlayThirdPlace($teamCount)) {
                $validator->errors()->add('plays_third_place', __(
                    'Se necesitan al menos 4 equipos para jugar un partido por el 3er y 4to puesto.'
                ));
            }
        });
    }
}
