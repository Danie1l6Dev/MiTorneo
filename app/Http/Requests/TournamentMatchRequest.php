<?php

namespace App\Http\Requests;

use App\Enums\MatchStatus;
use App\Models\Referee;
use App\Models\TournamentMatch;
use App\Services\MatchSchedulingConflictService;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The match edit page's single "Guardar cambios" form: status, referee and
 * when/where it's played (day, kickoff time, cancha).
 *
 * The schedule fields only take effect when they're actually posted -- a
 * request without scheduled_date / venue_id / referee_id leaves that part of
 * the match untouched. A posted blank day clears it. Whatever ends up
 * scheduled is checked against the rest of the calendar (see
 * MatchSchedulingConflictService) and every clash comes back under the
 * "schedule" error key, one message each.
 */
class TournamentMatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Checked here (not only in the controller) so a stranger gets a 403
        // before validation can echo clashes from someone else's calendar.
        $match = $this->route('match');

        return $match instanceof TournamentMatch && ($this->user()?->can('update', $match) ?? false);
    }

    /**
     * A failed save lands back on the form itself (its #programacion anchor)
     * instead of the top of this long page, where the clash list would be
     * scrolled out of sight.
     */
    protected function getRedirectUrl(): string
    {
        $match = $this->route('match');

        return $match instanceof TournamentMatch
            ? route('matches.edit', $match).'#programacion'
            : parent::getRedirectUrl();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(MatchStatus::class)],
            // Scoped to the authenticated organizer's own referees/canchas --
            // both are global to the organizer, but never across organizers.
            'referee_id' => ['nullable', Rule::exists('referees', 'id')->where('user_id', $this->user()?->id)],
            'scheduled_date' => ['nullable', 'date'],
            'kickoff_time' => ['nullable', 'date_format:H:i'],
            'venue_id' => ['nullable', Rule::exists('venues', 'id')->where('user_id', $this->user()?->id)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $match = $this->route('match');

            if ($validator->errors()->isNotEmpty() || ! $match instanceof TournamentMatch) {
                return;
            }

            if (filled($this->input('kickoff_time')) && blank($this->input('scheduled_date'))) {
                $validator->errors()->add('scheduled_date', __('Indica el día para poder asignar una hora.'));

                return;
            }

            $scheduledAt = $this->changesSchedule() ? $this->scheduledAt() : $match->scheduled_at;
            $venueId = $this->has('venue_id') ? $this->venueId() : $match->venue_id;
            $refereeId = $this->has('referee_id') ? $this->refereeId() : $match->referee_id;

            $scheduleChanged = $this->changesSchedule() && ! $this->sameInstant($scheduledAt, $match->scheduled_at);
            $venueChanged = $venueId !== $match->venue_id;
            $refereeChanged = $refereeId !== $match->referee_id;

            // Nothing that could create a clash was touched (or there is no
            // day to clash on): don't hold a status-only save hostage to
            // whatever the calendar already looked like.
            if ($scheduledAt === null || ! ($scheduleChanged || $venueChanged || ($refereeChanged && $scheduledAt->format('H:i') !== TournamentMatch::NO_KICKOFF_TIME))) {
                return;
            }

            $probe = clone $match;
            $probe->referee_id = $refereeId;
            $probe->setRelation('referee', $refereeId ? Referee::query()->find($refereeId) : null);

            foreach (app(MatchSchedulingConflictService::class)->conflictsFor($probe, $scheduledAt, $venueId) as $conflict) {
                $validator->errors()->add('schedule', $conflict);
            }
        });
    }

    /**
     * Whether the form carried the schedule at all (a blank day still counts:
     * it means "no day").
     */
    public function changesSchedule(): bool
    {
        return $this->has('scheduled_date');
    }

    /**
     * The chosen day + time as scheduled_at, or null when the day is blank. A
     * blank time is stored as the "hora por definir" marker (00:00).
     */
    public function scheduledAt(): ?CarbonInterface
    {
        if (blank($this->input('scheduled_date'))) {
            return null;
        }

        return TournamentMatch::composeScheduledAt((string) $this->input('scheduled_date'), $this->input('kickoff_time'));
    }

    public function venueId(): ?int
    {
        return filled($this->input('venue_id')) ? (int) $this->input('venue_id') : null;
    }

    public function refereeId(): ?int
    {
        return filled($this->input('referee_id')) ? (int) $this->input('referee_id') : null;
    }

    private function sameInstant(?CarbonInterface $a, ?CarbonInterface $b): bool
    {
        return $a === null || $b === null ? $a === $b : $a->equalTo($b);
    }
}
