<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesQueuedMatchEvents;
use App\Models\TournamentMatch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The "Guardar eventos" submit of the match edit page's quick-add queue --
 * see ValidatesQueuedMatchEvents for the rules (shared with
 * MatchResultRequest, which can carry the same queue along with a result).
 */
class MatchEventBatchRequest extends FormRequest
{
    use ValidatesQueuedMatchEvents;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->queuedEventRules(required: true);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $match = $this->route('match');

            if (! $match instanceof TournamentMatch) {
                return;
            }

            // Held to the result already registered, if any.
            $this->validateQueuedEvents($validator, $match, (array) $this->input('events', []), $match->goalsScored());
        });
    }
}
