<?php

namespace App\Http\Requests;

use App\Enums\CompetitionPhaseType;
use App\Models\CompetitionPhase;
use App\Services\PhaseEligibilityService;
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
        CompetitionPhaseType::Knockout,
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

            $standingsService = app(StandingsService::class);
            $eligibilityService = app(PhaseEligibilityService::class);
            $tables = $standingsService->tablesForPhase($phase);

            $tablesWithTeams = collect($tables)->filter(fn (array $table): bool => count($table['rows']) > 0);

            if ($tablesWithTeams->isEmpty()) {
                $validator->errors()->add('qualifiers_per_table', __(
                    'Todavía no hay ninguna tabla de posiciones con equipos para calcular los clasificados.'
                ));

                return;
            }

            // A league with a single standings table (no groups, or an
            // already-unified "liga de clasificados") has nothing left to
            // unify into another league -- from there the only ways forward
            // are declaring a champion directly or moving into a knockout.
            if ($type === CompetitionPhaseType::League && ! $eligibilityService->canAdvanceToLeague($tablesWithTeams->count())) {
                $validator->errors()->add('type', __(
                    'Esta fase ya tiene una sola tabla: no se puede crear otra liga a partir de ella. Declara campeón directamente o pasa a una fase de eliminación.'
                ));

                return;
            }

            $fixedTarget = $eligibilityService->fixedQualifierTarget($type);

            if ($fixedTarget !== null) {
                $perTable = $eligibilityService->perTableCountForTarget($fixedTarget, $tablesWithTeams->count());

                if ($perTable === null) {
                    $validator->errors()->add('qualifiers_per_table', __(
                        'No se pueden repartir :target clasificados en partes iguales entre las :tables tablas disponibles.',
                        ['target' => $fixedTarget, 'tables' => $tablesWithTeams->count()]
                    ));

                    return;
                }
            } else {
                $perTable = (int) $this->input('qualifiers_per_table');
            }

            $smallestTable = $tablesWithTeams->min(fn (array $table): int => count($table['rows']));

            if ($smallestTable < $perTable) {
                $validator->errors()->add('qualifiers_per_table', match ($type) {
                    CompetitionPhaseType::Semifinal => __(
                        'Para pasar a semifinales cada tabla necesita al menos :needed equipos, y la más chica solo tiene :available.',
                        ['needed' => $perTable, 'available' => $smallestTable]
                    ),
                    CompetitionPhaseType::Final => __(
                        'Para pasar a la final cada tabla necesita al menos :needed equipo(s), y la más chica solo tiene :available.',
                        ['needed' => $perTable, 'available' => $smallestTable]
                    ),
                    default => __(
                        'La tabla con menos equipos solo tiene :available, no puedes clasificar :count por tabla.',
                        ['available' => $smallestTable, 'count' => $perTable]
                    ),
                });

                return;
            }

            $isLeague = $type === CompetitionPhaseType::League;
            $totalQualifiers = $standingsService->topQualifiers($tables, $perTable)->count();

            if ($totalQualifiers < 2) {
                $validator->errors()->add('qualifiers_per_table', __(
                    'Se necesitan al menos 2 equipos clasificados en total para crear la siguiente fase.'
                ));

                return;
            }

            if (! $isLeague && ! $eligibilityService->isPowerOfTwo($totalQualifiers)) {
                $validator->errors()->add('qualifiers_per_table', __(
                    'Para una fase eliminatoria el número total de clasificados debe ser una potencia de 2 (2, 4, 8, 16...) para poder completar los cruces hasta la final. Con esta configuración serían :count.',
                    ['count' => $totalQualifiers]
                ));
            }
        });
    }
}
