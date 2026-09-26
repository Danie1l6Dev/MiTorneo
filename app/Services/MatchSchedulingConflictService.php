<?php

namespace App\Services;

use App\Enums\MatchStatus;
use App\Models\TournamentMatch;
use App\Models\Venue;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Finds the scheduling clashes a proposed day/time/cancha would create, for a
 * single match (reprogramming one) or a whole batch (the mass assignment
 * tool). Every clash it reports is blocking -- something that can't physically
 * happen -- and comes back as a ready-to-show Spanish message:
 *
 *  - the same TEAM with another match the same day (a team plays once a day,
 *    whatever the hour);
 *  - the same CANCHA with another match at an overlapping time (kickoff plus
 *    the category's match duration; only between matches that both have a
 *    kickoff time -- a day with "hora por definir" can't overlap anything);
 *  - the same REFEREE with another match at an overlapping time.
 *
 * Deliberately NOT checked: two teams of the same club playing at once. Each
 * category/group has its own plantel, so that isn't a conflict (the official
 * programming sheets themselves do it every fecha).
 *
 * Matches already in the database count as fixed unless they're part of the
 * batch being (re)scheduled -- those are compared by their proposed slot
 * instead, so moving a match never conflicts with its own old slot.
 * Postponed matches hold no slot (they're waiting for a new one).
 */
class MatchSchedulingConflictService
{
    /**
     * @return list<string>
     */
    public function conflictsFor(TournamentMatch $match, ?CarbonInterface $scheduledAt, ?int $venueId): array
    {
        return $this->conflictsForBatch(
            collect([$match]),
            [$match->id => ['at' => $scheduledAt, 'venue_id' => $venueId]],
        )[$match->id] ?? [];
    }

    /**
     * @param  Collection<int, TournamentMatch>  $matches  The matches being (re)scheduled.
     * @param  array<int, array{at: CarbonInterface|null, venue_id: int|null}>  $proposed  Their new slots, keyed by match id.
     * @return array<int, list<string>> Blocking clashes per match id (only matches with at least one).
     */
    public function conflictsForBatch(Collection $matches, array $proposed): array
    {
        (new EloquentCollection($matches->all()))->loadMissing(['category', 'homeTeam', 'awayTeam', 'referee']);

        $entries = [];

        foreach ($matches as $match) {
            $slot = $proposed[$match->id] ?? null;

            if ($slot === null || $slot['at'] === null) {
                continue;
            }

            $entries[] = $this->entry($match, $slot['at'], $slot['venue_id'], proposed: true);
        }

        if ($entries === []) {
            return [];
        }

        foreach ($this->existingMatches($matches, $entries) as $existing) {
            $entries[] = $this->entry($existing, $existing->scheduled_at, $existing->venue_id, proposed: false);
        }

        $venueNames = Venue::query()
            ->whereIn('id', collect($entries)->pluck('venue_id')->filter()->unique())
            ->pluck('name', 'id');

        $conflicts = [];

        foreach ($entries as $entry) {
            if (! $entry['proposed']) {
                continue;
            }

            foreach ($entries as $other) {
                if ($other['id'] === $entry['id']) {
                    continue;
                }

                foreach ($this->clashes($entry, $other, $venueNames) as $message) {
                    $conflicts[$entry['id']][] = $message;
                }
            }
        }

        return array_map(fn (array $messages): array => array_values(array_unique($messages)), $conflicts);
    }

    /**
     * @param  array{id: int, at: CarbonInterface, end: CarbonInterface|null, venue_id: int|null, teams: array<int, string>, referee_id: int|null, referee_name: string|null, label: string, proposed: bool}  $entry
     * @param  array{id: int, at: CarbonInterface, end: CarbonInterface|null, venue_id: int|null, teams: array<int, string>, referee_id: int|null, referee_name: string|null, label: string, proposed: bool}  $other
     * @param  Collection<int, string>  $venueNames
     * @return list<string>
     */
    private function clashes(array $entry, array $other, Collection $venueNames): array
    {
        $messages = [];

        $sharedTeams = array_intersect_key($entry['teams'], $other['teams']);

        if ($sharedTeams !== [] && $entry['at']->isSameDay($other['at'])) {
            $messages[] = trans_choice(':team ya juega ese día: :other.|:team ya juegan ese día: :other.', count($sharedTeams), [
                'team' => implode(' y ', $sharedTeams),
                'other' => $other['label'],
            ]);
        }

        $overlap = $entry['end'] !== null && $other['end'] !== null
            && $entry['at']->lt($other['end']) && $other['at']->lt($entry['end']);

        if ($overlap && $entry['venue_id'] !== null && $entry['venue_id'] === $other['venue_id']) {
            $messages[] = __(':venue está ocupada de :from a :to por :other.', [
                'venue' => $venueNames[$entry['venue_id']] ?? __('La cancha'),
                'from' => $other['at']->format('g:i A'),
                'to' => $other['end']->format('g:i A'),
                'other' => $other['label'],
            ]);
        }

        if ($overlap && $entry['referee_id'] !== null && $entry['referee_id'] === $other['referee_id']) {
            $messages[] = __(':referee ya dirige :other en ese horario.', [
                'referee' => $entry['referee_name'] ?? __('El árbitro'),
                'other' => $other['label'],
            ]);
        }

        return $messages;
    }

    /**
     * @return array{id: int, at: CarbonInterface, end: CarbonInterface|null, venue_id: int|null, teams: array<int, string>, referee_id: int|null, referee_name: string|null, label: string, proposed: bool}
     */
    private function entry(TournamentMatch $match, CarbonInterface $at, ?int $venueId, bool $proposed): array
    {
        $match->loadMissing(['category', 'homeTeam', 'awayTeam']);

        $hasTime = $at->format('H:i') !== TournamentMatch::NO_KICKOFF_TIME;

        return [
            'id' => $match->id,
            'at' => $at,
            'end' => $hasTime ? $at->copy()->addMinutes($match->category->matchDurationMinutes()) : null,
            'venue_id' => $venueId,
            'teams' => array_filter([
                $match->home_team_id => $match->homeTeam?->name,
                $match->away_team_id => $match->awayTeam?->name,
            ]),
            'referee_id' => $match->referee_id,
            'referee_name' => $match->referee?->full_name,
            'label' => $this->label($match, $at, $hasTime),
            'proposed' => $proposed,
        ];
    }

    private function label(TournamentMatch $match, CarbonInterface $at, bool $hasTime): string
    {
        return sprintf(
            '%s vs %s (%s, %s)',
            $match->homeTeam?->name ?? __('Por definir'),
            $match->awayTeam?->name ?? __('Por definir'),
            $match->category->name,
            $at->format($hasTime ? 'd/m g:i A' : 'd/m'),
        );
    }

    /**
     * Already-scheduled matches (outside the batch) that could clash with any
     * proposed slot: same day AND sharing a team, cancha or referee with one
     * of them.
     *
     * @param  Collection<int, TournamentMatch>  $batch
     * @param  list<array{id: int, at: CarbonInterface, end: CarbonInterface|null, venue_id: int|null, teams: array<int, string>, referee_id: int|null, referee_name: string|null, label: string, proposed: bool}>  $entries
     * @return Collection<int, TournamentMatch>
     */
    private function existingMatches(Collection $batch, array $entries): Collection
    {
        $days = collect($entries)->map(fn (array $entry): string => $entry['at']->toDateString())->unique();
        $teamIds = collect($entries)->flatMap(fn (array $entry): array => array_keys($entry['teams']))->unique()->values();
        $venueIds = collect($entries)->pluck('venue_id')->filter()->unique()->values();
        $refereeIds = collect($entries)->pluck('referee_id')->filter()->unique()->values();

        return TournamentMatch::query()
            ->whereNotIn('id', $batch->pluck('id'))
            ->whereNotNull('scheduled_at')
            ->where('status', '!=', MatchStatus::Postponed)
            ->where(function (Builder $query) use ($days): void {
                foreach ($days as $day) {
                    $start = Carbon::parse($day)->startOfDay();

                    $query->orWhereBetween('scheduled_at', [$start, $start->copy()->endOfDay()]);
                }
            })
            ->where(function (Builder $query) use ($teamIds, $venueIds, $refereeIds): void {
                $query->whereIn('home_team_id', $teamIds)
                    ->orWhereIn('away_team_id', $teamIds)
                    ->orWhereIn('venue_id', $venueIds)
                    ->orWhereIn('referee_id', $refereeIds);
            })
            ->with(['category', 'homeTeam', 'awayTeam', 'referee'])
            ->get();
    }
}
