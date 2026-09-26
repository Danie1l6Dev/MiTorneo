<?php

namespace App\Services;

use App\Enums\CompetitionPhaseType;
use App\Enums\MatchStatus;
use App\Models\Category;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The official "programación" sheet: the still-to-be-played matches of one
 * or more fechas (league jornada numbers) across a whole tournament -- or
 * just one of its categories -- modeled on the programming sheets Faudis'
 * league already hands out. Laid out fecha > category > (day + venue)
 * block, so every block is one "LUGAR / DÍA" table like the reference.
 * Only league phases count: a knockout phase's round numbers don't line up
 * with the tournament's fechas.
 */
class MatchProgrammingReportService
{
    /**
     * Every fecha with at least one pending match, ascending -- what the
     * export picker offers.
     *
     * @return list<int>
     */
    public function pendingRounds(Tournament $tournament, ?Category $category = null): array
    {
        return $this->pendingMatches($tournament, $category)
            ->whereNotNull('round_number')
            ->distinct()
            ->orderBy('round_number')
            ->pluck('round_number')
            ->map(fn ($round): int => (int) $round)
            ->all();
    }

    /**
     * Every still-to-be-played league match of one fecha across the whole
     * tournament -- what the mass programming tool assigns days and hours to.
     * Matches locked by an expulsion walkover never take part.
     *
     * @return Builder<TournamentMatch>
     */
    public function pendingRoundMatches(Tournament $tournament, int $roundNumber): Builder
    {
        return $this->pendingMatches($tournament, null)
            ->where('round_number', $roundNumber)
            ->where('is_walkover', false);
    }

    /**
     * How far along each fecha's programming is: every match of the fecha that
     * still has to be played or was already played (cancelled ones don't count),
     * and how many of those are "ready" -- they already have a day, or were
     * already played (finished), even if they never had a date. What the
     * "Programar fecha" picker shows on each fecha's tile.
     *
     * @return array<int, array{total: int, ready: int}>
     */
    public function roundSummaries(Tournament $tournament): array
    {
        return TournamentMatch::query()
            ->where('tournament_id', $tournament->id)
            ->whereNotNull('home_team_id')
            ->whereNotNull('away_team_id')
            ->whereNotNull('round_number')
            ->where('status', '!=', MatchStatus::Cancelled)
            ->whereHas('competitionPhase', fn (Builder $query) => $query->where('type', CompetitionPhaseType::League))
            ->selectRaw('round_number, count(*) as total, sum(case when status = ? or scheduled_at is not null then 1 else 0 end) as ready', [MatchStatus::Finished->value])
            ->groupBy('round_number')
            ->orderBy('round_number')
            ->get()
            ->mapWithKeys(fn ($row): array => [(int) $row->round_number => ['total' => (int) $row->total, 'ready' => (int) $row->ready]])
            ->all();
    }

    /**
     * Everything the "Programar fecha" page needs, in one go: every fecha that
     * still has pending matches, each with its progress and its categories
     * (youngest first) -- and per category its progress plus the pending
     * matches themselves, in play order. The page keeps this in the browser and
     * filters it there, so switching fecha or category never reloads anything.
     *
     * @return list<array{number: int, title: string, total: int, ready: int, categories: list<array{id: int, name: string, total: int, ready: int, matches: list<array{id: int, home: string, away: string, group: string|null, has_day: bool, current: string|null}>}>}>
     */
    public function programmingCatalog(Tournament $tournament): array
    {
        $pending = $this->pendingMatches($tournament, null)
            ->whereNotNull('round_number')
            ->where('is_walkover', false)
            ->with(['homeTeam', 'awayTeam', 'group', 'category', 'venue'])
            ->get();

        // Progress counts every match of the fecha (played ones included), not
        // just the pending ones listed below.
        $roundStats = $this->roundSummaries($tournament);
        $categoryStats = TournamentMatch::query()
            ->where('tournament_id', $tournament->id)
            ->whereNotNull('home_team_id')
            ->whereNotNull('away_team_id')
            ->whereNotNull('round_number')
            ->where('status', '!=', MatchStatus::Cancelled)
            ->whereHas('competitionPhase', fn (Builder $query) => $query->where('type', CompetitionPhaseType::League))
            ->selectRaw('round_number, category_id, count(*) as total, sum(case when status = ? or scheduled_at is not null then 1 else 0 end) as ready', [MatchStatus::Finished->value])
            ->groupBy('round_number', 'category_id')
            ->get()
            ->groupBy('round_number');

        $categoryOrder = Category::query()
            ->whereIn('id', $pending->pluck('category_id')->unique())
            ->orderedByAge()
            ->pluck('id')
            ->flip();

        return $pending
            ->groupBy('round_number')
            ->sortKeys()
            ->map(function (Collection $roundMatches, int $round) use ($roundStats, $categoryStats, $categoryOrder): array {
                $categories = $roundMatches
                    ->groupBy('category_id')
                    ->sortBy(fn (Collection $matches, int $categoryId): int => $categoryOrder[$categoryId])
                    ->map(function (Collection $matches, int $categoryId) use ($round, $categoryStats): array {
                        $stat = $categoryStats[$round]?->firstWhere('category_id', $categoryId);

                        return [
                            'id' => $categoryId,
                            'name' => $matches->first()->category->name,
                            'total' => (int) ($stat->total ?? $matches->count()),
                            'ready' => (int) ($stat->ready ?? 0),
                            'matches' => $matches
                                ->sortBy([
                                    fn (TournamentMatch $a, TournamentMatch $b): int => ($a->group?->order ?? 0) <=> ($b->group?->order ?? 0),
                                    fn (TournamentMatch $a, TournamentMatch $b): int => ($a->group_id ?? 0) <=> ($b->group_id ?? 0),
                                    fn (TournamentMatch $a, TournamentMatch $b): int => $a->id <=> $b->id,
                                ])
                                ->map(fn (TournamentMatch $match): array => [
                                    'id' => $match->id,
                                    'home' => $match->homeTeam->name,
                                    'away' => $match->awayTeam->name,
                                    'group' => $match->group?->name,
                                    'has_day' => $match->scheduled_at !== null,
                                    'current' => $match->scheduleSummary(),
                                ])
                                ->values()
                                ->all(),
                        ];
                    })
                    ->values()
                    ->all();

                return [
                    'number' => $round,
                    'title' => $this->roundTitle($round),
                    'total' => $roundStats[$round]['total'] ?? 0,
                    'ready' => $roundStats[$round]['ready'] ?? 0,
                    'categories' => $categories,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The pending matches of $roundNumbers (every pending fecha when null),
     * one section per fecha, split by category (youngest first), then into
     * one block per day + venue: days ascending with undated matches last,
     * venues alphabetically with "no venue" last. Inside a block, matches
     * go by kickoff time, then group.
     *
     * @param  list<int>|null  $roundNumbers
     * @return list<array{round: int, title: string, categories: list<array{category: Category, blocks: list<array{day: Carbon|null, venue: string|null, matches: Collection<int, TournamentMatch>}>}>}>
     */
    public function sections(Tournament $tournament, ?array $roundNumbers, ?Category $category = null): array
    {
        $matches = $this->pendingMatches($tournament, $category)
            ->whereNotNull('round_number')
            ->when($roundNumbers !== null, fn (Builder $query) => $query->whereIn('round_number', $roundNumbers))
            ->with(['homeTeam', 'awayTeam', 'group', 'category', 'venue'])
            ->get();

        $categoryOrder = Category::query()
            ->whereIn('id', $matches->pluck('category_id')->unique())
            ->orderedByAge()
            ->pluck('id')
            ->flip();

        return $matches
            ->groupBy('round_number')
            ->sortKeys()
            ->map(fn (Collection $roundMatches, int $round): array => [
                'round' => $round,
                'title' => $this->roundTitle($round),
                'categories' => $roundMatches
                    ->groupBy('category_id')
                    ->sortBy(fn (Collection $categoryMatches, int $categoryId): int => $categoryOrder[$categoryId])
                    ->map(fn (Collection $categoryMatches): array => [
                        'category' => $categoryMatches->first()->category,
                        'blocks' => $this->blocks($categoryMatches),
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, TournamentMatch>  $matches
     * @return list<array{day: Carbon|null, venue: string|null, matches: Collection<int, TournamentMatch>}>
     */
    private function blocks(Collection $matches): array
    {
        return $matches
            ->groupBy(fn (TournamentMatch $match): string => ($match->scheduled_at?->toDateString() ?? '9999-99-99').'|'.$this->normalizedVenue($match))
            ->sortKeys()
            ->map(fn (Collection $blockMatches): array => [
                'day' => $blockMatches->first()->scheduled_at?->copy()->startOfDay(),
                'venue' => $blockMatches->first()->venue?->name,
                'matches' => $blockMatches
                    ->sortBy([
                        fn (TournamentMatch $a, TournamentMatch $b): int => ($a->hasKickoffTime() ? $a->scheduled_at->timestamp : PHP_INT_MAX) <=> ($b->hasKickoffTime() ? $b->scheduled_at->timestamp : PHP_INT_MAX),
                        fn (TournamentMatch $a, TournamentMatch $b): int => ($a->group?->order ?? 0) <=> ($b->group?->order ?? 0),
                    ])
                    ->values(),
            ])
            ->values()
            ->all();
    }

    /**
     * Sorts blocks by cancha name, with matches that have no cancha after
     * every named one ("~" sorts after letters).
     */
    private function normalizedVenue(TournamentMatch $match): string
    {
        $venue = mb_strtolower(trim((string) $match->venue?->name));

        return $venue === '' ? '~' : $venue;
    }

    /**
     * "Quinta fecha", like the reference sheets -- plain "Fecha 25" past
     * the spelled-out ones.
     */
    public function roundTitle(int $roundNumber): string
    {
        $ordinals = [
            1 => 'Primera', 2 => 'Segunda', 3 => 'Tercera', 4 => 'Cuarta', 5 => 'Quinta',
            6 => 'Sexta', 7 => 'Séptima', 8 => 'Octava', 9 => 'Novena', 10 => 'Décima',
            11 => 'Undécima', 12 => 'Duodécima', 13 => 'Decimotercera', 14 => 'Decimocuarta',
            15 => 'Decimoquinta', 16 => 'Decimosexta', 17 => 'Decimoséptima', 18 => 'Decimoctava',
            19 => 'Decimonovena', 20 => 'Vigésima',
        ];

        return isset($ordinals[$roundNumber])
            ? __(':ordinal fecha', ['ordinal' => $ordinals[$roundNumber]])
            : __('Fecha :number', ['number' => $roundNumber]);
    }

    /**
     * Still-to-be-played league matches of $tournament (optionally one
     * category) whose two teams are already known.
     *
     * @return Builder<TournamentMatch>
     */
    private function pendingMatches(Tournament $tournament, ?Category $category): Builder
    {
        return TournamentMatch::query()
            ->where('tournament_id', $tournament->id)
            ->when($category, fn (Builder $query) => $query->where('category_id', $category->id))
            ->whereIn('status', [MatchStatus::Scheduled, MatchStatus::Postponed])
            ->whereNotNull('home_team_id')
            ->whereNotNull('away_team_id')
            ->whereHas('competitionPhase', fn (Builder $query) => $query->where('type', CompetitionPhaseType::League));
    }
}
