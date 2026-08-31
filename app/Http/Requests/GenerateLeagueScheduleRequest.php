<?php

namespace App\Http\Requests;

use App\Enums\CompetitionPhaseType;
use App\Enums\ScheduleFormat;
use App\Models\CompetitionPhase;
use App\Models\Group;
use App\Models\LeagueSchedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class GenerateLeagueScheduleRequest extends FormRequest
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
            'format' => ['required', Rule::enum(ScheduleFormat::class)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $phase = $this->route('phase');

            if (! $phase instanceof CompetitionPhase) {
                return;
            }

            if ($phase->type !== CompetitionPhaseType::League) {
                $validator->errors()->add('format', __('El calendario automático solo está disponible para fases de tipo Liga.'));

                return;
            }

            $category = $phase->category;

            if ($category->uses_groups && $category->groups()->doesntExist()) {
                $validator->errors()->add('format', __('La categoría todavía no tiene grupos configurados.'));

                return;
            }

            /** @var Collection<int, Group|null> $scopes */
            $scopes = $category->uses_groups
                ? $category->groups
                : collect([null]);

            foreach ($scopes as $group) {
                $label = $group instanceof Group ? $group->name : $category->name;
                $groupId = $group instanceof Group ? $group->id : null;
                $teamsCount = $group instanceof Group ? $group->teams()->count() : $category->teams()->count();

                if ($teamsCount < 2) {
                    $validator->errors()->add('format', __('No hay suficientes equipos en :label para generar un calendario (mínimo 2).', ['label' => $label]));

                    continue;
                }

                $alreadyExists = LeagueSchedule::query()
                    ->where('competition_phase_id', $phase->id)
                    ->where('group_id', $groupId)
                    ->exists();

                if ($alreadyExists) {
                    $validator->errors()->add('format', __('Ya existe un calendario generado para :label. Elimina o gestiona el calendario existente antes de generar uno nuevo.', ['label' => $label]));
                }
            }
        });
    }
}
