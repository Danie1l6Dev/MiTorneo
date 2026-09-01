<?php

namespace App\Http\Controllers;

use App\Enums\CompetitionPhaseType;
use App\Enums\MatchStatus;
use App\Enums\ScheduleFormat;
use App\Http\Requests\CompetitionPhaseRequest;
use App\Models\Category;
use App\Models\CompetitionPhase;
use App\Models\LeagueSchedule;
use App\Models\Team;
use App\Models\TournamentMatch;
use App\Services\StandingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class CompetitionPhaseController extends Controller
{
    public function create(Category $category): View
    {
        $this->authorize('create', [CompetitionPhase::class, $category]);

        return view('pages.phases.create', compact('category'));
    }

    public function store(CompetitionPhaseRequest $request, Category $category): RedirectResponse
    {
        $this->authorize('create', [CompetitionPhase::class, $category]);

        $phase = $category->competitionPhases()->make($request->validated());
        $phase->tournament_id = $category->tournament_id;
        $phase->save();

        return to_route('phases.show', $phase);
    }

    public function show(CompetitionPhase $phase, StandingsService $standingsService): View
    {
        $this->authorize('view', $phase);

        $category = $phase->category;

        $schedules = $phase->leagueSchedules()
            ->with(['group.teams', 'matches.homeTeam', 'matches.awayTeam'])
            ->get()
            ->map(fn (LeagueSchedule $schedule): array => $this->buildScheduleView($schedule, $phase));

        $bracketRounds = $phase->type !== CompetitionPhaseType::League
            ? $this->buildBracketRounds($phase)
            : [];

        $bracketColumns = $this->buildBracketColumns($bracketRounds);

        $champion = $this->championFor($bracketRounds);

        $bracketMatchIds = collect($bracketRounds)->flatMap(fn (array $round): Collection => $round['matches'])->pluck('id');

        $unscheduledMatches = $phase->matches()
            ->with(['homeTeam', 'awayTeam'])
            ->whereNull('league_schedule_id')
            ->whereNotIn('id', $bracketMatchIds)
            ->get();

        $standings = $phase->type === CompetitionPhaseType::League
            ? $standingsService->tablesForPhase($phase)
            : [];

        $readyToAdvance = $phase->type === CompetitionPhaseType::League && $phase->allMatchesFinished();

        return view('pages.phases.show', compact('phase', 'category', 'schedules', 'bracketRounds', 'bracketColumns', 'champion', 'unscheduledMatches', 'standings', 'readyToAdvance'));
    }

    /**
     * The winner of the bracket's final match, once it's been played -- null
     * while the phase has no bracket at all, or its final hasn't finished yet.
     *
     * @param  array<int, array{round_number: int, label: string, matches: Collection<int, TournamentMatch>}>  $bracketRounds
     */
    private function championFor(array $bracketRounds): ?Team
    {
        if (empty($bracketRounds)) {
            return null;
        }

        $final = end($bracketRounds)['matches']->first();

        if (! $final instanceof TournamentMatch || $final->status !== MatchStatus::Finished) {
            return null;
        }

        return $final->home_score > $final->away_score ? $final->homeTeam : $final->awayTeam;
    }

    /**
     * Group a knockout-style phase's matches by round, labeling each round by
     * its traditional bracket name (derived purely from how many matches it
     * has: a round with 1 match is the final, 2 is the semifinal, 4 is the
     * quarterfinal, and so on) rather than by the phase's own type -- a
     * "Semifinal"-type phase already starts at its semifinal round, and a
     * "Knockout"-type phase can start anywhere depending on how many teams
     * qualified.
     *
     * @return array<int, array{round_number: int, label: string, matches: Collection<int, TournamentMatch>}>
     */
    private function buildBracketRounds(CompetitionPhase $phase): array
    {
        return $phase->matches()
            ->with(['homeTeam', 'awayTeam'])
            ->orderBy('round_number')
            ->orderBy('id')
            ->get()
            ->groupBy('round_number')
            ->sortKeys()
            ->map(fn (Collection $roundMatches, int $roundNumber): array => [
                'round_number' => $roundNumber,
                'label' => $this->knockoutRoundLabel($roundMatches->count()),
                'matches' => $roundMatches->values(),
            ])
            ->values()
            ->all();
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
     * @param  array<int, array{round_number: int, label: string, matches: Collection<int, TournamentMatch>}>  $bracketRounds
     * @return array<int, array{side: string, label: string, matches: Collection<int, TournamentMatch>}>
     */
    private function buildBracketColumns(array $bracketRounds): array
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

    public function edit(CompetitionPhase $phase): View
    {
        $this->authorize('update', $phase);

        return view('pages.phases.edit', compact('phase'));
    }

    public function update(CompetitionPhaseRequest $request, CompetitionPhase $phase): RedirectResponse
    {
        $this->authorize('update', $phase);

        $phase->update($request->validated());

        return to_route('phases.show', $phase);
    }

    public function destroy(CompetitionPhase $phase): RedirectResponse
    {
        $this->authorize('delete', $phase);

        $category = $phase->category;

        $phase->delete();

        return to_route('categories.show', $category);
    }
}
