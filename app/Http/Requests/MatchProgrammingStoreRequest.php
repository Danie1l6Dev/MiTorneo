<?php

namespace App\Http\Requests;

use App\Models\TournamentMatch;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Step 2 of the "Programar fecha" tool: the (possibly hand-edited) day, time
 * and cancha of every proposed match. A match left without a day is skipped,
 * not cleared. The scheduling-clash check runs in the controller, so a clash
 * can bring the preview back instead of just an error banner.
 */
class MatchProgrammingStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('tournament')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'round' => ['required', 'integer', 'min:1'],
            'category' => ['nullable', 'integer'],
            'overwrite' => ['nullable', 'boolean'],
            'matches' => ['required', 'array'],
            'matches.*.date' => ['nullable', 'date'],
            'matches.*.time' => ['nullable', 'date_format:H:i'],
            'matches.*.venue_id' => ['nullable', Rule::exists('venues', 'id')->where('user_id', $this->user()?->id)],
        ];
    }

    /**
     * The chosen slot of every match that was given a day, keyed by match id.
     *
     * @return array<int, array{at: CarbonInterface, venue_id: int|null}>
     */
    public function proposed(): array
    {
        $proposed = [];

        foreach ((array) $this->input('matches', []) as $matchId => $slot) {
            if (blank($slot['date'] ?? null)) {
                continue;
            }

            $proposed[(int) $matchId] = [
                'at' => TournamentMatch::composeScheduledAt((string) $slot['date'], $slot['time'] ?? null),
                'venue_id' => filled($slot['venue_id'] ?? null) ? (int) $slot['venue_id'] : null,
            ];
        }

        return $proposed;
    }
}
