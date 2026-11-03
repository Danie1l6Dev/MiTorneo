<?php

namespace App\Http\Requests;

use App\Enums\CompetitionPhaseType;
use App\Enums\DrawMethod;
use App\Enums\PairingScope;
use App\Enums\ScheduleFormat;
use App\Models\CompetitionPhase;
use App\Services\CrossRoundService;
use App\Services\PhaseEligibilityService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AdvanceCrossRoundRequest extends FormRequest
{
    /**
     * Same pseudo-type AdvancePhaseRequest uses: another ronda de cruces,
     * stored as a knockout phase flagged `cross_round`.
     */
    public const CROSS_ROUND = AdvancePhaseRequest::CROSS_ROUND;

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
            'type' => ['required', Rule::in([
                CompetitionPhaseType::Knockout->value,
                CompetitionPhaseType::Semifinal->value,
                CompetitionPhaseType::Final->value,
                self::CROSS_ROUND,
            ])],
            'best_losers' => ['required', 'integer', 'min:0'],
            'draw_method' => ['required', Rule::enum(DrawMethod::class)],
            'pairing_scope' => ['required', Rule::enum(PairingScope::class)],
            'knockout_format' => ['nullable', Rule::enum(ScheduleFormat::class)],
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
            'best_losers.required' => __('Indica cuántos de los mejores perdedores clasifican (puede ser 0).'),
            'best_losers.integer' => __('La cantidad de mejores perdedores debe ser un número entero.'),
            'best_losers.min' => __('La cantidad de mejores perdedores no puede ser negativa.'),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $phase = $this->route('phase');

            if (! $phase instanceof CompetitionPhase) {
                return;
            }

            $outcome = app(CrossRoundService::class)->outcome($phase);
            $bestLosers = (int) $this->input('best_losers');

            if ($bestLosers > count($outcome['losers'])) {
                $validator->errors()->add('best_losers', __('Solo hay :count perdedores en la ronda, no puedes clasificar :requested.', ['count' => count($outcome['losers']), 'requested' => $bestLosers]));

                return;
            }

            $service = app(CrossRoundService::class);
            $total = $outcome['direct']->count() + $outcome['winners']->count() + $bestLosers;
            $type = (string) $this->input('type');
            $eligibility = app(PhaseEligibilityService::class);

            $error = match (true) {
                $total < 2 => __('Se necesitan al menos 2 equipos clasificados en total para crear la siguiente fase.'),
                $type === self::CROSS_ROUND && $total % 2 !== 0 => __('Para una nueva ronda de cruces el total de clasificados debe ser par, y con esta configuración serían :count. Ajusta los mejores perdedores.', ['count' => $total]),
                $type === CompetitionPhaseType::Knockout->value && ! $eligibility->isPowerOfTwo($total) => __('Para una fase eliminatoria el número total de clasificados debe ser una potencia de 2 (2, 4, 8, 16...) para poder completar los cruces hasta la final. Con esta configuración serían :count.', ['count' => $total]),
                $type === CompetitionPhaseType::Semifinal->value && $total !== 4 => __('Las semifinales necesitan exactamente 4 clasificados, y con esta configuración serían :count.', ['count' => $total]),
                $type === CompetitionPhaseType::Final->value && $total !== 2 => __('La final necesita exactamente 2 clasificados, y con esta configuración serían :count.', ['count' => $total]),
                $type !== self::CROSS_ROUND && $this->boolean('plays_third_place') && ! $eligibility->canPlayThirdPlace($total) => __('Se necesitan al menos 4 equipos clasificados en total para jugar un partido por el 3er y 4to puesto. Con esta configuración serían :count.', ['count' => $total]),
                default => null,
            };

            if ($error !== null) {
                $validator->errors()->add('best_losers', $error);

                return;
            }

            // Whether the teams can actually be paired the way asked for (e.g.
            // "entre grupos" needs at least two groups among the qualifiers).
            $qualifiers = $outcome['direct']->concat($outcome['winners'])->concat(collect($outcome['losers'])->take($bestLosers)->pluck('team'));
            $pairing = $service->pairPool($qualifiers, $service->seedingMeta($phase), DrawMethod::from((string) $this->input('draw_method')), PairingScope::from((string) $this->input('pairing_scope')));

            if ($pairing['error'] !== null) {
                $validator->errors()->add('pairing_scope', $pairing['error']);
            }
        });
    }
}
