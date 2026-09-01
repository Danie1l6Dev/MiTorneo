<?php

namespace App\Http\Requests;

use App\Enums\CompetitionPhaseType;
use App\Models\CompetitionPhase;
use App\Services\StandingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AdvancePhaseRequest extends FormRequest
{
    /**
     * Phase types where the exact number of qualifiers per table isn't
     * implied by the type itself, so the form must ask for it.
     */
    private const TYPES_REQUIRING_QUALIFIER_COUNT = [
        CompetitionPhaseType::League,
        CompetitionPhaseType::GroupStage,
        CompetitionPhaseType::Knockout,
        CompetitionPhaseType::Custom,
    ];

    /**
     * Fixed qualifiers-per-table for phase types that imply their own bracket
     * size: a semifinal always needs 2 teams per table, a final always needs 1.
     */
    private const FIXED_QUALIFIER_COUNTS = [
        'semifinal' => 2,
        'final' => 1,
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $type = CompetitionPhaseType::tryFrom((string) $this->input('type'));
        $requiresCount = $type !== null && in_array($type, self::TYPES_REQUIRING_QUALIFIER_COUNT, true);

        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(CompetitionPhaseType::class)],
            'qualifiers_per_table' => [
                $requiresCount ? 'required' : 'prohibited',
                'integer',
                'min:1',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'qualifiers_per_table.prohibited' => __('Para semifinales y final la cantidad de clasificados se calcula automáticamente; no envíes este campo.'),
            'qualifiers_per_table.required' => __('Indica cuántos equipos clasifican de cada tabla.'),
            'qualifiers_per_table.integer' => __('La cantidad de equipos que clasifican debe ser un número entero.'),
            'qualifiers_per_table.min' => __('Debe clasificar al menos 1 equipo por tabla.'),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('qualifiers_per_table') || $validator->errors()->has('type')) {
                return;
            }

            $phase = $this->route('phase');

            if (! $phase instanceof CompetitionPhase) {
                return;
            }

            $type = CompetitionPhaseType::from((string) $this->input('type'));

            $perTable = self::FIXED_QUALIFIER_COUNTS[$type->value] ?? (int) $this->input('qualifiers_per_table');

            $standingsService = app(StandingsService::class);
            $tables = $standingsService->tablesForPhase($phase);

            $tablesWithTeams = collect($tables)->filter(fn (array $table): bool => count($table['rows']) > 0);

            if ($tablesWithTeams->isEmpty()) {
                $validator->errors()->add('qualifiers_per_table', __(
                    'Todavía no hay ninguna tabla de posiciones con equipos para calcular los clasificados.'
                ));

                return;
            }

            $smallestTable = $tablesWithTeams->min(fn (array $table): int => count($table['rows']));

            if ($smallestTable < $perTable) {
                $validator->errors()->add('qualifiers_per_table', match ($type) {
                    CompetitionPhaseType::Semifinal => __(
                        'Para pasar a semifinales cada tabla necesita al menos 2 equipos, y la más chica solo tiene :available.',
                        ['available' => $smallestTable]
                    ),
                    CompetitionPhaseType::Final => __(
                        'Para pasar a la final cada tabla necesita al menos 1 equipo, y alguna todavía no tiene ninguno.'
                    ),
                    default => __(
                        'La tabla con menos equipos solo tiene :available, no puedes clasificar :count por tabla.',
                        ['available' => $smallestTable, 'count' => $perTable]
                    ),
                });

                return;
            }

            $isLeague = in_array($type, [CompetitionPhaseType::League, CompetitionPhaseType::GroupStage], true);
            $totalQualifiers = $standingsService->topQualifiers($tables, $perTable)->count();

            if ($totalQualifiers < 2) {
                $validator->errors()->add('qualifiers_per_table', __(
                    'Se necesitan al menos 2 equipos clasificados en total para crear la siguiente fase.'
                ));

                return;
            }

            if (! $isLeague && ! $this->isPowerOfTwo($totalQualifiers)) {
                $validator->errors()->add('qualifiers_per_table', __(
                    'Para una fase eliminatoria el número total de clasificados debe ser una potencia de 2 (2, 4, 8, 16...) para poder completar los cruces hasta la final. Con esta configuración serían :count.',
                    ['count' => $totalQualifiers]
                ));
            }
        });
    }

    private function isPowerOfTwo(int $n): bool
    {
        return $n >= 2 && ($n & ($n - 1)) === 0;
    }
}
