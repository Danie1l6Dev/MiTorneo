<?php

namespace App\Http\Requests;

use App\Enums\CompetitionPhaseType;
use App\Enums\DrawMethod;
use App\Enums\PairingScope;
use App\Enums\ScheduleFormat;
use App\Models\CompetitionPhase;
use App\Services\CrossRoundService;
use App\Services\PhaseEligibilityService;
use App\Services\StandingsService;
use App\Services\TiebreakDrawService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AdvancePhaseRequest extends FormRequest
{
    /**
     * Not a CompetitionPhaseType case: a "ronda de cruces" is stored as a
     * knockout phase flagged `cross_round`, but it's offered (and validated)
     * as its own choice since it takes different inputs.
     */
    public const CROSS_ROUND = 'cross_round';

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
        $isCrossRound = $this->input('type') === self::CROSS_ROUND;
        $requiresCount = $type !== null && in_array($type, self::TYPES_REQUIRING_QUALIFIER_COUNT, true);
        $isLeague = $type === CompetitionPhaseType::League;

        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in([...array_column(CompetitionPhaseType::cases(), 'value'), self::CROSS_ROUND])],
            // Only for a ronda de cruces: how many teams qualify from each
            // table and how many of those (the best ones) pass directly,
            // both keyed by the table's position.
            'qualifiers' => [$isCrossRound ? 'required' : 'prohibited', 'array'],
            'qualifiers.*' => ['integer', 'min:0'],
            'direct' => [$isCrossRound ? 'nullable' : 'prohibited', 'array'],
            'direct.*' => ['integer', 'min:0'],
            // Also only for a ronda de cruces: whether teams may only meet
            // a team from another group, or anyone.
            'pairing_scope' => [$isCrossRound ? 'nullable' : 'prohibited', Rule::enum(PairingScope::class)],
            'qualifiers_per_table' => [
                $requiresCount ? 'required' : 'prohibited',
                'integer',
                'min:1',
            ],
            'draw_method' => [
                $isLeague ? 'prohibited' : 'required',
                Rule::enum(DrawMethod::class),
            ],
            // Ignored (and stored as null) for a league phase either way,
            // and defaults to a single match per cross when omitted.
            'knockout_format' => ['nullable', Rule::enum(ScheduleFormat::class)],
            // Both ignored (and stored as null/false) for a league phase.
            // plays_third_place is further gated in withValidator() below on
            // the actual total qualifier count -- see
            // PhaseEligibilityService::canPlayThirdPlace().
            'plays_third_place' => ['sometimes', 'boolean'],
            'final_knockout_format' => ['nullable', Rule::enum(ScheduleFormat::class)],
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
            'draw_method.prohibited' => __('Una fase de liga no tiene sorteo de cruces; no envíes este campo.'),
            'draw_method.required' => __('Indica cómo se sortearán los cruces.'),
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

            if ($this->input('type') === self::CROSS_ROUND) {
                $this->validateCrossRound($validator, $phase);

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

            foreach ($tablesWithTeams as $table) {
                $names = app(TiebreakDrawService::class)->pendingTieOnLine($table['rows'], $perTable);

                if ($names !== null) {
                    $validator->errors()->add('qualifiers_per_table', app(TiebreakDrawService::class)->lineMessage($table['label'], $names));

                    return;
                }
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

                return;
            }

            if (! $isLeague && $this->boolean('plays_third_place') && ! $eligibilityService->canPlayThirdPlace($totalQualifiers)) {
                $validator->errors()->add('plays_third_place', __(
                    'Se necesitan al menos 4 equipos clasificados en total para jugar un partido por el 3er y 4to puesto. Con esta configuración serían :count.',
                    ['count' => $totalQualifiers]
                ));
            }
        });
    }

    private function validateCrossRound(Validator $validator, CompetitionPhase $phase): void
    {
        if ($validator->errors()->hasAny(['qualifiers', 'qualifiers.*', 'direct', 'direct.*', 'draw_method', 'pairing_scope'])) {
            return;
        }

        $tables = app(StandingsService::class)->tablesForPhase($phase);
        $counts = array_map('intval', (array) $this->input('qualifiers', []));
        $direct = array_map('intval', (array) $this->input('direct', []));

        foreach (array_values($tables) as $index => $table) {
            $available = count($table['rows']);
            $qualifying = $counts[$index] ?? 0;

            if ($qualifying > $available) {
                $validator->errors()->add('qualifiers', __(':table solo tiene :available equipos, no puedes clasificar :count.', ['table' => $table['label'], 'available' => $available, 'count' => $qualifying]));

                return;
            }

            if (($direct[$index] ?? 0) > $qualifying) {
                $validator->errors()->add('qualifiers', __('En :table no pueden pasar directo más equipos de los que clasifican.', ['table' => $table['label']]));

                return;
            }
        }

        foreach (array_values($tables) as $index => $table) {
            foreach ([$counts[$index] ?? 0, $direct[$index] ?? 0] as $line) {
                $names = app(TiebreakDrawService::class)->pendingTieOnLine($table['rows'], $line);

                if ($names !== null) {
                    $validator->errors()->add('qualifiers', app(TiebreakDrawService::class)->lineMessage($table['label'], $names));

                    return;
                }
            }
        }

        $scope = $this->filled('pairing_scope') ? PairingScope::from((string) $this->input('pairing_scope')) : null;
        $plan = app(CrossRoundService::class)->plan($tables, $counts, $direct, DrawMethod::from((string) $this->input('draw_method')), $scope);

        if ($plan['error'] !== null) {
            $validator->errors()->add('qualifiers', $plan['error']);
        }
    }
}
