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
