<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Step 1 of the "Programar fecha" tool: which fecha, and per category/group
 * a day, cancha, first kickoff time and rest between matches. Nothing here is saved -- it
 * only asks for a proposal (see TournamentProgrammingController::preview()).
 */
class MatchProgrammingPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('tournament')) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['overwrite' => $this->boolean('overwrite')]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $ownVenue = Rule::exists('venues', 'id')->where('user_id', $this->user()?->id);

        return [
            'round' => ['required', 'integer', 'min:1'],
            'overwrite' => ['boolean'],
            'rows' => ['required', 'array'],
            'rows.*.date' => ['nullable', 'date'],
            'rows.*.venue_id' => ['nullable', $ownVenue],
            'rows.*.start' => ['nullable', 'date_format:H:i'],
            'rows.*.rest' => ['nullable', 'integer', 'min:0', 'max:120'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $rows = (array) $this->input('rows', []);

            if (collect($rows)->every(fn ($row): bool => blank($row['date'] ?? null))) {
                $validator->errors()->add('rows', __('Elige el día de al menos una categoría para poder programar.'));

                return;
            }

            foreach ($rows as $key => $row) {
                if (filled($row['start'] ?? null) && blank($row['date'] ?? null)) {
                    $validator->errors()->add("rows.{$key}.date", __('Indica el día para poder asignar la hora inicial.'));
                }
            }
        });
    }

    /**
     * @return array<string, array{date: string|null, venue_id: string|null, start: string|null, rest: string|null}>
     */
    public function rowsConfig(): array
    {
        return collect((array) $this->input('rows', []))
            ->map(fn ($row): array => [
                'date' => $row['date'] ?? null,
                'venue_id' => $row['venue_id'] ?? null,
                'start' => $row['start'] ?? null,
                'rest' => $row['rest'] ?? null,
            ])
            ->all();
    }
}
