<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The calculation behind the "Programar fecha" tool: given ONE fecha of a
 * tournament and, per category/group, a day + cancha (+ optionally a first
 * kickoff time and the minutes between matches), proposes a day/time/cancha
 * for every pending match. Pure -- it never saves anything; the controller
 * shows the proposal (with MatchSchedulingConflictService's clashes) and only
 * then applies it.
 */
class MatchProgrammingPlannerService
{
    public function __construct(private readonly MatchProgrammingReportService $report) {}

    /**
     * The matches the tool can assign for $round: pending league matches with
     * no day yet -- or every pending one when $overwrite is on.
     *
     * @return Collection<int, TournamentMatch>
     */
    public function candidates(Tournament $tournament, int $round, bool $overwrite, ?Category $category = null): Collection
    {
        return $this->report->pendingRoundMatches($tournament, $round)
            ->when($category, fn ($query) => $query->where('category_id', $category->id))
            ->when(! $overwrite, fn ($query) => $query->whereNull('scheduled_at'))
            ->with(['homeTeam', 'awayTeam', 'category', 'group', 'venue', 'referee'])
            ->get();
    }

    /**
     * One row per category (and group, when it has them), youngest category
     * first -- the unit a day, cancha and start time are chosen for.
     *
     * @param  Collection<int, TournamentMatch>  $matches
     * @return list<array{key: string, category: Category, group: string|null, matches: Collection<int, TournamentMatch>}>
     */
    public function rows(Collection $matches): array
    {
        $categoryOrder = Category::query()
            ->whereIn('id', $matches->pluck('category_id')->unique())
            ->orderedByAge()
            ->pluck('id')
            ->flip();

        return $matches
            ->sortBy([
                fn (TournamentMatch $a, TournamentMatch $b): int => $categoryOrder[$a->category_id] <=> $categoryOrder[$b->category_id],
                fn (TournamentMatch $a, TournamentMatch $b): int => ($a->group?->order ?? 0) <=> ($b->group?->order ?? 0),
                fn (TournamentMatch $a, TournamentMatch $b): int => ($a->group_id ?? 0) <=> ($b->group_id ?? 0),
                fn (TournamentMatch $a, TournamentMatch $b): int => $a->id <=> $b->id,
            ])
            ->groupBy(fn (TournamentMatch $match): string => static::rowKey($match))
            ->map(fn (Collection $rowMatches, string $key): array => [
                'key' => $key,
                'category' => $rowMatches->first()->category,
                'group' => $rowMatches->first()->group?->name,
                'matches' => $rowMatches->values(),
            ])
            ->values()
            ->all();
    }

    /**
     * One category's matches of a fecha in the order they'll be played, with a
     * single day/cancha/first-hour/rest applied to all of them. A category
     * with groups is chained group after group.
     *
     * @param  Collection<int, TournamentMatch>  $matches  All belonging to one category.
     * @param  array{date?: string|null, venue_id?: int|string|null, start?: string|null, rest?: int|string|null}  $config
     * @return array<int, array{at: CarbonInterface, venue_id: int|null}> Keyed by match id.
     */
    public function planCategory(Collection $matches, array $config): array
    {
        $rows = $this->rows($matches);

        return $this->plan($rows, collect($rows)->mapWithKeys(fn (array $row): array => [$row['key'] => $config])->all());
    }

    public static function rowKey(TournamentMatch $match): string
    {
        return $match->category_id.'-'.($match->group_id ?? 0);
    }

    /**
     * The proposed slot of every match of a row that was given a day.
     *
     * Rows are handled in their listed order (youngest category first). When
     * a row has a start time its matches get consecutive kickoffs spaced by
     * the category's match duration plus an optional rest between matches
     * (none by default: one match ends and the next one starts); a later row on
     * the SAME cancha and day starts where the previous one ended when its own
     * start time would overlap it -- that's how several categories share one
     * cancha in a morning. With no start time the matches only get the day
     * ("hora por definir").
     *
     * @param  list<array{key: string, category: Category, group: string|null, matches: Collection<int, TournamentMatch>}>  $rows
     * @param  array<string, array{date?: string|null, venue_id?: int|string|null, start?: string|null, rest?: int|string|null}>  $config  Keyed by row key.
     * @return array<int, array{at: CarbonInterface, venue_id: int|null}> Keyed by match id.
     */
    public function plan(array $rows, array $config): array
    {
        $proposed = [];
        $venueFreeAt = [];

        foreach ($rows as $row) {
            $rowConfig = $config[$row['key']] ?? [];
            $date = $rowConfig['date'] ?? null;

            if (blank($date)) {
                continue;
            }

            $venueId = filled($rowConfig['venue_id'] ?? null) ? (int) $rowConfig['venue_id'] : null;
            $start = $rowConfig['start'] ?? null;
            $interval = $row['category']->matchDurationMinutes() + (filled($rowConfig['rest'] ?? null) ? (int) $rowConfig['rest'] : 0);

            if (blank($start)) {
                foreach ($row['matches'] as $match) {
                    $proposed[$match->id] = ['at' => TournamentMatch::composeScheduledAt($date, null), 'venue_id' => $venueId];
                }

                continue;
            }

            $cursor = TournamentMatch::composeScheduledAt($date, $start);
            $slotKey = Carbon::parse($date)->toDateString().'|'.$venueId;

            if (isset($venueFreeAt[$slotKey]) && $venueFreeAt[$slotKey]->gt($cursor)) {
                $cursor = $venueFreeAt[$slotKey]->copy();
            }

            foreach ($row['matches'] as $match) {
                $proposed[$match->id] = ['at' => $cursor->copy(), 'venue_id' => $venueId];
                $cursor = $cursor->copy()->addMinutes($interval);
            }

            $venueFreeAt[$slotKey] = $cursor;
        }

        return $proposed;
    }
}
