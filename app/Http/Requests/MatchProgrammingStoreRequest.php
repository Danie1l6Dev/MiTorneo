<?php

namespace App\Http\Requests;

use App\Models\TournamentMatch;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Step 2 of the "Programar jornada" tool: the (possibly hand-edited) day, time
 * and cancha of every proposed match. A match with nothing filled in, or marked "no programar" (a team that
 * can't play), is skipped, not cleared. The scheduling-clash check runs in the controller, so a clash
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
            'matches.*.referee_id' => ['nullable', Rule::exists('referees', 'id')->where('user_id', $this->user()?->id)],
            'matches.*.skip' => ['nullable', 'boolean'],
        ];
    }

    /**
     * The chosen slot of every match given a day, a cancha or a referee and not skipped, keyed by match id.
     *
     * @return array<int, array{at: CarbonInterface|null, venue_id: int|null, referee_id: int|null}>
     */
    public function proposed(): array
    {
        $proposed = [];

        foreach ((array) $this->input('matches', []) as $matchId => $slot) {
            if (filter_var($slot['skip'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                continue;
            }

            $venueId = filled($slot['venue_id'] ?? null) ? (int) $slot['venue_id'] : null;
            $refereeId = filled($slot['referee_id'] ?? null) ? (int) $slot['referee_id'] : null;

            // Nothing filled in: nothing to save. A cancha / referee alone is enough
            // (no day: the match's day stays as it was).
            if (blank($slot['date'] ?? null) && $venueId === null && $refereeId === null) {
                continue;
            }

            $proposed[(int) $matchId] = [
                'at' => filled($slot['date'] ?? null) ? TournamentMatch::composeScheduledAt((string) $slot['date'], $slot['time'] ?? null) : null,
                'venue_id' => $venueId,
                'referee_id' => $refereeId,
            ];
        }

        return $proposed;
    }
}
