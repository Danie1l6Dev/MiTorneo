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
use App\Services\KnockoutBracketService;
use App\Services\PhaseEligibilityService;
use App\Services\StandingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CompetitionPhaseController extends Controller
{
    public function create(Category $category, PhaseEligibilityService $eligibilityService): View|RedirectResponse
    {
        $this->authorize('create', [CompetitionPhase::class, $category]);

        if ($redirect = $this->guardFirstPhase($category)) {
            return $redirect;
        }

        $typeOptions = $eligibilityService->firstPhaseTypeOptions($category);

        return view('pages.phases.create', compact('category', 'typeOptions'));
    }

    public function store(CompetitionPhaseRequest $request, Category $category, PhaseEligibilityService $eligibilityService, KnockoutBracketService $bracketService): RedirectResponse
    {
        $this->authorize('create', [CompetitionPhase::class, $category]);

        if ($redirect = $this->guardFirstPhase($category)) {
            return $redirect;
        }

        $type = CompetitionPhaseType::from($request->validated('type'));

        if (! $eligibilityService->firstPhaseTypeAllowed($category, $type)) {
            throw ValidationException::withMessages([
                'type' => __('Ese tipo de fase no está disponible todavía para esta categoría.'),
            ]);
        }

        $phase = DB::transaction(function () use ($category, $request, $type, $bracketService): CompetitionPhase {
            $phase = new CompetitionPhase;
            $phase->tournament_id = $category->tournament_id;
            $phase->category_id = $category->id;
            $phase->name = (string) $request->validated('name');
            $phase->type = $type;
            // Only ever one first phase per category: everything after it is
            // chained from a finished phase's qualifiers via the advancement
            // flow, which is what assigns every later phase's order.
            $phase->order = 1;
            $phase->save();

            if ($type !== CompetitionPhaseType::League) {
                $bracketService->generateBracket($phase, $category->teams);
            }

            return $phase;
        });

        $redirect = to_route('phases.show', $phase);

        if ($type !== CompetitionPhaseType::League) {
            // Same single-use flash the advancement flow uses, so a knockout
            // created straight from a category's team list also gets the
            // live draw reveal instead of showing its bracket instantly.
            $redirect->with('drawReveal', true);
        }

        return $redirect;
    }

    /**
     * A category may only ever get its first phase created directly (there's
     * no standings table yet to draw qualifiers from); every phase after it
     * is created from an already-finished phase via the advancement flow.
     */
    private function guardFirstPhase(Category $category): ?RedirectResponse
    {
        if ($category->competitionPhases()->exists()) {
            return to_route('categories.show', $category)->with('error', __(
                'Esta categoría ya tiene una fase inicial. Para crear la siguiente, marca su fase de liga como finalizada y define los clasificados desde ahí.'
            ));
        }

        return null;
    }

    public function show(CompetitionPhase $phase, StandingsService $standingsService, PhaseEligibilityService $eligibilityService): View
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

        $bracketSize = $this->bracketSizeTokens(count($bracketRounds));

        $standings = $phase->type === CompetitionPhaseType::League
            ? $standingsService->tablesForPhase($phase)
            : [];

        // A knockout-type phase's champion comes from its bracket's final
        // once played; a league-type phase's only ever comes from directly
        // declaring one (see PhaseChampionController), never from a match.
        $champion = $phase->type === CompetitionPhaseType::League
            ? $phase->champion
            : $this->championFor($bracketRounds);

        $tableCount = collect($standings)->filter(fn (array $table): bool => count($table['rows']) > 0)->count();

        $isAlreadyResolved = $eligibilityService->isAlreadyResolved($phase);

        $readyToAdvance = $phase->type === CompetitionPhaseType::League
            && $phase->allMatchesFinished()
            && ! $isAlreadyResolved;

        $canDeclareChampion = $eligibilityService->canDeclareChampion($phase, $tableCount);

        return view('pages.phases.show', compact(
            'phase', 'category', 'schedules', 'bracketRounds', 'bracketColumns', 'bracketSize',
            'champion', 'standings', 'readyToAdvance', 'isAlreadyResolved', 'canDeclareChampion'
        ));
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
    private function bracketSizeTokens(int $roundCount): array
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

        if (! $final instanceof TournamentMatch) {
            return null;
        }

        $winnerTeamId = $final->winnerTeamId();

        if ($winnerTeamId === null) {
            return null;
        }

        return $winnerTeamId === $final->home_team_id ? $final->homeTeam : $final->awayTeam;
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

    public function edit(CompetitionPhase $phase, PhaseEligibilityService $eligibilityService): View
    {
        $this->authorize('update', $phase);

        $typeIsLocked = $phase->matches()->exists();
        $typeOptions = $this->isFirstPhase($phase) ? $eligibilityService->firstPhaseTypeOptions($phase->category) : null;

        return view('pages.phases.edit', compact('phase', 'typeIsLocked', 'typeOptions'));
    }

    public function update(CompetitionPhaseRequest $request, CompetitionPhase $phase, PhaseEligibilityService $eligibilityService): RedirectResponse
    {
        $this->authorize('update', $phase);

        $type = CompetitionPhaseType::from($request->validated('type'));

        // Once a schedule or bracket has been generated for this phase, its
        // type can no longer change: that content was built for the old
        // type (round-robin fixtures for a league, a single-elimination
        // bracket otherwise) and swapping types out from under it would
        // leave matches that no longer match how the phase is played.
        if ($type !== $phase->type && $phase->matches()->exists()) {
            throw ValidationException::withMessages([
                'type' => __('No se puede cambiar el tipo de una fase que ya tiene partidos generados.'),
            ]);
        }

        // The category's first phase is still bound by the same sporting
        // rules it was created under (groups need an independent league,
        // and a knockout needs a bracket-sized team count).
        if ($this->isFirstPhase($phase) && ! $eligibilityService->firstPhaseTypeAllowed($phase->category, $type)) {
            throw ValidationException::withMessages([
                'type' => __('Ese tipo de fase no está disponible todavía para esta categoría.'),
            ]);
        }

        $phase->update($request->validated());

        return to_route('phases.show', $phase);
    }

    /**
     * Whether $phase is the category's first phase -- the only one created
     * directly rather than chained from a previous phase's qualifiers, and
     * so the only one still bound by categories.phases' eligibility rules.
     */
    private function isFirstPhase(CompetitionPhase $phase): bool
    {
        return $phase->order === 1;
    }

    public function destroy(CompetitionPhase $phase): RedirectResponse
    {
        $this->authorize('delete', $phase);

        $category = $phase->category;

        $phase->delete();

        return to_route('categories.show', $category);
    }
}
