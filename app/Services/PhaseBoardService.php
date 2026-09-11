<?php

namespace App\Services;

use App\Enums\MatchEventType;
use App\Enums\MatchStatus;
use App\Enums\ScheduleFormat;
use App\Enums\StatisticsPhaseScope;
use App\Models\Category;
use App\Models\CompetitionPhase;
use App\Models\Group;
use App\Models\LeagueSchedule;
use App\Models\Player;
use App\Models\Team;
use App\Models\TournamentMatch;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Pure, read-only view-model building for a phase's own page (schedule/bracket
 * shape, sizing, statistics selection) -- extracted out of
 * CompetitionPhaseController so the admin phase page and the public portal's
 * phase page (see App\Http\Controllers\Public\PublicPhaseController) share the
 * exact same computation and queries instead of duplicating them. Nothing
 * here persists anything or checks authorization -- that stays the caller's
 * responsibility.
 */
class PhaseBoardService
{
    /**
     * @return Collection<int, array{schedule: LeagueSchedule, rounds: array<int, array{round_number: int, leg: int, matches: Collection<int, TournamentMatch>, resting_team: Team|null}>, start_round_index: int}>
     */
    public function scheduleViews(CompetitionPhase $phase): Collection
    {
        return $phase->leagueSchedules()
            ->with(['group.teams', 'matches.homeTeam', 'matches.awayTeam', 'matches.goals'])
            ->get()
            ->map(fn (LeagueSchedule $schedule): array => $this->buildScheduleView($schedule, $phase));
    }

    /**
     * @return array{schedule: LeagueSchedule, rounds: array<int, array{round_number: int, leg: int, matches: Collection<int, TournamentMatch>, resting_team: Team|null}>, start_round_index: int}
     */
    private function buildScheduleView(LeagueSchedule $schedule, CompetitionPhase $phase): array
    {
        $roster = $phase->teams;
        $teams = $schedule->group ? $schedule->group->teams : ($roster->isNotEmpty() ? $roster : $phase->category->teams);

        $roundsCount = $schedule->matches->pluck('round_number')->unique()->count();
        $firstLegRounds = $schedule->format === ScheduleFormat::HomeAndAway ? intdiv($roundsCount, 2) : $roundsCount;

        $rounds = $schedule->matches
            ->groupBy('round_number')
            ->sortKeys()
            ->map(function (Collection $roundMatches, int $roundNumber) use ($teams, $firstLegRounds): array {
                $playingTeamIds = $roundMatches->flatMap(fn (TournamentMatch $match): array => [$match->home_team_id, $match->away_team_id]);

                return [
                    'round_number' => $roundNumber,
                    'leg' => $roundNumber > $firstLegRounds ? 2 : 1,
                    'matches' => $roundMatches->values(),
                    'resting_team' => $teams->first(fn (Team $team): bool => ! $playingTeamIds->contains($team->id)),
                ];
            })
            ->values()
            ->all();

        // Jump straight to the first round that still has an unfinished match
        // (i.e. the "current" jornada) instead of always opening on round 1;
        // once every round is finished, land on the last one.
        $startRoundIndex = collect($rounds)->search(
            fn (array $round): bool => collect($round['matches'])->contains(
                fn (TournamentMatch $match): bool => $match->status !== MatchStatus::Finished
            )
        );

        if ($startRoundIndex === false) {
            $startRoundIndex = max(count($rounds) - 1, 0);
        }

        return ['schedule' => $schedule, 'rounds' => $rounds, 'start_round_index' => $startRoundIndex];
    }

    /**
     * Group a knockout-style phase's matches by round and then by cross (a
     * cross is either one match, or -- for a two-legged phase -- a first leg
     * paired with its second), labeling each round by its traditional
     * bracket name (derived purely from how many CROSSES it has: a round
     * with 1 cross is the final, 2 is the semifinal, 4 is the quarterfinal,
     * and so on -- never from the raw match count, which would double for a
     * two-legged phase) rather than by the phase's own type -- a
     * "Semifinal"-type phase already starts at its semifinal round, and a
     * "Knockout"-type phase can start anywhere depending on how many teams
     * qualified.
     *
     * @return array<int, array{round_number: int, label: string, matches: Collection<int, Collection<int, TournamentMatch>>}>
     */
    public function bracketRounds(CompetitionPhase $phase): array
    {
        return $phase->matches()
            ->with(['homeTeam', 'awayTeam', 'goals', 'firstLeg'])
            ->orderBy('round_number')
            ->orderBy('id')
            ->get()
            ->groupBy('round_number')
            ->sortKeys()
            ->map(function (Collection $roundMatches, int $roundNumber): array {
                $crosses = $this->groupIntoCrosses($roundMatches);

                return [
                    'round_number' => $roundNumber,
                    'label' => $this->knockoutRoundLabel($crosses->count()),
                    'matches' => $crosses,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Pair up one round's matches into their crosses: every first leg (its
     * first_leg_match_id is null) paired with its second leg, if any -- a
     * single-match cross just stays alone. Order is preserved (both a
     * cross's own legs, leg1 before leg2, and the crosses themselves) since
     * $roundMatches already arrives ordered by id and a first leg is always
     * created before its second.
     *
     * @param  Collection<int, TournamentMatch>  $roundMatches
     * @return Collection<int, Collection<int, TournamentMatch>>
     */
    private function groupIntoCrosses(Collection $roundMatches): Collection
    {
        $secondLegsByFirstLegId = $roundMatches
            ->filter(fn (TournamentMatch $match): bool => $match->first_leg_match_id !== null)
            ->keyBy('first_leg_match_id');

        return $roundMatches
            ->reject(fn (TournamentMatch $match): bool => $match->first_leg_match_id !== null)
            ->map(function (TournamentMatch $firstLeg) use ($secondLegsByFirstLegId): Collection {
                $secondLeg = $secondLegsByFirstLegId->get($firstLeg->id);

                return $secondLeg !== null ? collect([$firstLeg, $secondLeg]) : collect([$firstLeg]);
            })
            ->values();
    }

    private function knockoutRoundLabel(int $matchesInRound): string
    {
        return match ($matchesInRound) {
            1 => __('Final'),
            2 => __('Semifinal'),
            4 => __('Cuartos de final'),
            8 => __('Octavos de final'),
            16 => __('Dieciseisavos de final'),
            default => __('Ronda de :count', ['count' => $matchesInRound * 2]),
        };
    }

    /**
     * Lay $bracketRounds out for the classic two-sided bracket view: every
     * round except the final is split into its left and right half (a round's
     * first half of matches always converges into the left half of the next
     * round, its second half into the right half -- a direct consequence of
     * how KnockoutBracketService pairs adjacent matches into the round after),
     * ordered outside-in on the left, then the single final column, then the
     * same rounds mirrored outside-in on the right.
     *
     * @param  array<int, array{round_number: int, label: string, matches: Collection<int, Collection<int, TournamentMatch>>}>  $bracketRounds
     * @return array<int, array{side: string, label: string, matches: Collection<int, Collection<int, TournamentMatch>>}>
     */
    public function bracketColumns(array $bracketRounds): array
    {
        if (empty($bracketRounds)) {
            return [];
        }

        $finalRound = end($bracketRounds);
        $earlierRounds = array_slice($bracketRounds, 0, -1);

        $left = array_map(fn (array $round): array => [
            'side' => 'left',
            'label' => $round['label'],
            'matches' => $round['matches']->slice(0, intdiv($round['matches']->count(), 2))->values(),
        ], $earlierRounds);

        $right = array_reverse(array_map(fn (array $round): array => [
            'side' => 'right',
            'label' => $round['label'],
            'matches' => $round['matches']->slice(intdiv($round['matches']->count(), 2))->values(),
        ], $earlierRounds));

        $final = [[
            'side' => 'final',
            'label' => $finalRound['label'],
            'matches' => $finalRound['matches'],
        ]];

        return [...$left, ...$final, ...$right];
    }

    /**
     * Sizing for the desktop bracket, scaled to how many rounds it has: a
     * short bracket (e.g. starting straight from the semifinal) gets larger
     * cards so it doesn't look sparse in the available space, while a deep
     * one (e.g. starting from octavos) gets smaller cards so the whole thing
     * still fits reasonably without excessive horizontal scrolling.
     *
     * Every class string below is written out in full, per tier, rather than
     * assembled from parts at runtime: Tailwind's build only generates CSS
     * for a utility it finds as *literal* contiguous text in a scanned file
     * (see the @source line in app.css pointing it at this file) -- a class
     * name pieced together via PHP interpolation (e.g. `"after:{$offset}"`)
     * never appears as such anywhere and would silently produce no CSS.
     *
     * @return array<string, string>
     */
    public function bracketSizeTokens(int $roundCount): array
    {
        return match (true) {
            $roundCount <= 2 => [
                'card' => 'h-20',
                'row' => 'h-10',
                'text' => 'text-base',
                // flex-1 lets a column shrink or grow to whatever room is actually
                // available (never forcing horizontal scroll), max-w caps how wide
                // it gets on a roomy screen so a short bracket doesn't look stretched.
                'column' => 'flex-1 min-w-0 max-w-80',
                'columnGap' => 'gap-24',
                'pairGap' => 'gap-14',
                'pairWrapperLeft' => "relative flex flex-col justify-between gap-14 after:content-[''] after:absolute after:top-10 after:bottom-10 after:w-0.5 after:bg-zinc-400 dark:after:bg-white/35 after:-right-12 before:content-[''] before:absolute before:top-1/2 before:h-0.5 before:bg-zinc-400 dark:before:bg-white/35 before:w-12 before:-right-24",
                'pairWrapperRight' => "relative flex flex-col justify-between gap-14 after:content-[''] after:absolute after:top-10 after:bottom-10 after:w-0.5 after:bg-zinc-400 dark:after:bg-white/35 after:-left-12 before:content-[''] before:absolute before:top-1/2 before:h-0.5 before:bg-zinc-400 dark:before:bg-white/35 before:w-12 before:-left-24",
                'cardStubLeft' => "relative after:content-[''] after:absolute after:top-1/2 after:h-0.5 after:w-12 after:bg-zinc-400 dark:after:bg-white/35 after:-right-12",
                'cardStubRight' => "relative before:content-[''] before:absolute before:top-1/2 before:h-0.5 before:w-12 before:bg-zinc-400 dark:before:bg-white/35 before:-left-12",
                'singleStubLeft' => "relative after:content-[''] after:absolute after:top-1/2 after:h-0.5 after:w-24 after:bg-zinc-400 dark:after:bg-white/35 after:-right-24",
                'singleStubRight' => "relative before:content-[''] before:absolute before:top-1/2 before:h-0.5 before:w-24 before:bg-zinc-400 dark:before:bg-white/35 before:-left-24",
            ],
            $roundCount === 3 => [
                'card' => 'h-16',
                'row' => 'h-8',
                'text' => 'text-sm',
                'column' => 'flex-1 min-w-0 max-w-72',
                'columnGap' => 'gap-16',
                'pairGap' => 'gap-12',
                'pairWrapperLeft' => "relative flex flex-col justify-between gap-12 after:content-[''] after:absolute after:top-8 after:bottom-8 after:w-0.5 after:bg-zinc-400 dark:after:bg-white/35 after:-right-8 before:content-[''] before:absolute before:top-1/2 before:h-0.5 before:bg-zinc-400 dark:before:bg-white/35 before:w-8 before:-right-16",
                'pairWrapperRight' => "relative flex flex-col justify-between gap-12 after:content-[''] after:absolute after:top-8 after:bottom-8 after:w-0.5 after:bg-zinc-400 dark:after:bg-white/35 after:-left-8 before:content-[''] before:absolute before:top-1/2 before:h-0.5 before:bg-zinc-400 dark:before:bg-white/35 before:w-8 before:-left-16",
                'cardStubLeft' => "relative after:content-[''] after:absolute after:top-1/2 after:h-0.5 after:w-8 after:bg-zinc-400 dark:after:bg-white/35 after:-right-8",
                'cardStubRight' => "relative before:content-[''] before:absolute before:top-1/2 before:h-0.5 before:w-8 before:bg-zinc-400 dark:before:bg-white/35 before:-left-8",
                'singleStubLeft' => "relative after:content-[''] after:absolute after:top-1/2 after:h-0.5 after:w-16 after:bg-zinc-400 dark:after:bg-white/35 after:-right-16",
                'singleStubRight' => "relative before:content-[''] before:absolute before:top-1/2 before:h-0.5 before:w-16 before:bg-zinc-400 dark:before:bg-white/35 before:-left-16",
            ],
            default => [
                'card' => 'h-12',
                'row' => 'h-6',
                'text' => 'text-xs',
                'column' => 'flex-1 min-w-0 max-w-56',
                'columnGap' => 'gap-10',
                'pairGap' => 'gap-10',
                'pairWrapperLeft' => "relative flex flex-col justify-between gap-10 after:content-[''] after:absolute after:top-6 after:bottom-6 after:w-0.5 after:bg-zinc-400 dark:after:bg-white/35 after:-right-5 before:content-[''] before:absolute before:top-1/2 before:h-0.5 before:bg-zinc-400 dark:before:bg-white/35 before:w-5 before:-right-10",
                'pairWrapperRight' => "relative flex flex-col justify-between gap-10 after:content-[''] after:absolute after:top-6 after:bottom-6 after:w-0.5 after:bg-zinc-400 dark:after:bg-white/35 after:-left-5 before:content-[''] before:absolute before:top-1/2 before:h-0.5 before:bg-zinc-400 dark:before:bg-white/35 before:w-5 before:-left-10",
                'cardStubLeft' => "relative after:content-[''] after:absolute after:top-1/2 after:h-0.5 after:w-5 after:bg-zinc-400 dark:after:bg-white/35 after:-right-5",
                'cardStubRight' => "relative before:content-[''] before:absolute before:top-1/2 before:h-0.5 before:w-5 before:bg-zinc-400 dark:before:bg-white/35 before:-left-5",
                'singleStubLeft' => "relative after:content-[''] after:absolute after:top-1/2 after:h-0.5 after:w-10 after:bg-zinc-400 dark:after:bg-white/35 after:-right-10",
                'singleStubRight' => "relative before:content-[''] before:absolute before:top-1/2 before:h-0.5 before:w-10 before:bg-zinc-400 dark:before:bg-white/35 before:-left-10",
            ],
        };
    }

    /**
     * The winner of the bracket's final cross, once it's been decided -- null
     * while the phase has no bracket at all, or its final hasn't been
     * decided yet (for a two-legged final, that means both legs finished).
     *
     * @param  array<int, array{round_number: int, label: string, matches: Collection<int, Collection<int, TournamentMatch>>}>  $bracketRounds
     */
    public function championFromBracket(array $bracketRounds): ?Team
    {
        if (empty($bracketRounds)) {
            return null;
        }

        $finalCross = end($bracketRounds)['matches']->first();

        if (! $finalCross instanceof Collection) {
            return null;
        }

        // The decisive leg: the only match in a single-match cross, the
        // second (and last-created) one in a two-legged cross.
        $decisive = $finalCross->last();

        if (! $decisive instanceof TournamentMatch) {
            return null;
        }

        $winnerTeamId = $decisive->tieWinnerTeamId();

        if ($winnerTeamId === null) {
            return null;
        }

        return $winnerTeamId === $decisive->home_team_id ? $decisive->homeTeam : $decisive->awayTeam;
    }

    /**
     * Every player-statistics leaderboard (goal/assist/yellow_card/red_card)
     * for $category, under EVERY group/phase-scope combination it could be
     * viewed with -- computed together so the phase page can render all of
     * them as already-loaded x-show panels. Switching between
     * "Goleadores"/"Asistidores"/etc., between "Todos los grupos"/a specific
     * group, and between "Solo fase de liga"/"Toda la competición" are then
     * all pure client-side toggles, exactly like "Tabla"/"Calendario"
     * already were -- none of the nine (four types times up to two scopes
     * times however many group options) combinations needs a fresh page
     * load. Shared verbatim between the admin phase page and the public
     * portal's phase page so neither one re-derives its own request-parsing
     * or re-queries CompetitionStatisticsService differently.
     *
     * Only ONE query per (type, phase-scope) pair actually hits the
     * database -- the unfiltered ("todos los grupos") leaderboard, which
     * already carries each row's `team.group` (eager-loaded by
     * CompetitionStatisticsService::leaderboard() itself). Every
     * per-group panel is sliced out of that same result in PHP
     * (re-ranked, since a group's own #1 isn't necessarily the category's
     * overall #1) instead of running a second, near-identical query per
     * group -- the exact "avoid repeating queries" reasoning this service
     * already exists for.
     *
     * @return array{groupOptions: Collection<int, Group>, group: Group|null, phaseScope: StatisticsPhaseScope, panels: array<string, array<string, array<string, array<int, array{rank: int, player: Player, count: int}>>>>}
     */
    public function statisticsPanels(Request $request, Category $category, CompetitionStatisticsService $statisticsService): array
    {
        $groupOptions = $category->uses_groups ? $category->groups->sortBy('order')->values() : new Collection;

        // Never a raw Group::find() -- resolving through the category's own
        // relation makes a group id from another category simply not match
        // anything, instead of needing a separate ownership check.
        $activeGroup = $category->uses_groups
            ? $category->groups->firstWhere('id', $request->integer('group'))
            : null;

        $activePhaseScope = StatisticsPhaseScope::tryFrom((string) $request->query('phase'))
            ?? StatisticsPhaseScope::League;

        $panels = [];

        foreach (MatchEventType::cases() as $type) {
            foreach (StatisticsPhaseScope::cases() as $scope) {
                $allRows = $statisticsService->leaderboard($category, $type, null, $scope);

                $panels[$type->value][$scope->value]['all'] = $allRows;

                foreach ($groupOptions as $group) {
                    $panels[$type->value][$scope->value][(string) $group->id] = $this->reRank(
                        array_values(array_filter(
                            $allRows,
                            fn (array $row): bool => $row['player']->team->group_id === $group->id
                        ))
                    );
                }
            }
        }

        return [
            'groupOptions' => $groupOptions,
            'group' => $activeGroup,
            'phaseScope' => $activePhaseScope,
            'panels' => $panels,
        ];
    }

    /**
     * Re-number a leaderboard slice's 'rank' from 1, in its existing order
     * -- used after filtering a category-wide leaderboard down to one
     * group's rows, since a group's own #1 scorer otherwise keeps whatever
     * rank they held in the unfiltered (category-wide) list.
     *
     * @param  array<int, array{rank: int, player: Player, count: int}>  $rows
     * @return array<int, array{rank: int, player: Player, count: int}>
     */
    private function reRank(array $rows): array
    {
        return array_values(array_map(
            fn (array $row, int $index): array => [...$row, 'rank' => $index + 1],
            $rows,
            array_keys($rows)
        ));
    }
}
