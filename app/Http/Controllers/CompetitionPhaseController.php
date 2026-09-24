<?php

namespace App\Http\Controllers;

use App\Enums\CompetitionPhaseType;
use App\Enums\ScheduleFormat;
use App\Http\Requests\CompetitionPhaseRequest;
use App\Models\Category;
use App\Models\CompetitionPhase;
use App\Models\Tournament;
use App\Services\CompetitionStatisticsService;
use App\Services\KnockoutBracketService;
use App\Services\MatchProgrammingReportService;
use App\Services\PhaseBoardService;
use App\Services\PhaseEligibilityService;
use App\Services\StandingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CompetitionPhaseController extends Controller
{
    public function create(Category $category, PhaseEligibilityService $eligibilityService): View|RedirectResponse
    {
        // Authorize BEFORE resolving a tournament: a category with no
        // tournament at all must fail with the policy's 403, not with
        // resolveSoleTournament()'s 404 -- and PHP evaluates this method's
        // arguments (including resolveSoleTournament()) before entering its
        // body, so that call can't be inlined into the createForm() call
        // below without running ahead of this check.
        $this->authorize('create', [CompetitionPhase::class, $category]);

        return $this->createForm($category, $category->resolveSoleTournament(), $eligibilityService);
    }

    /**
     * Same as create(), but for a category inscribed in more than one
     * tournament -- reached from that tournament's own edition of the
     * category (tournaments.categories.show), where which tournament is
     * meant is never ambiguous because it's right there in the URL. See
     * Category::resolveSoleTournament()'s docblock for the case this
     * sidesteps.
     */
    public function createForTournament(Tournament $tournament, Category $category, PhaseEligibilityService $eligibilityService): View|RedirectResponse
    {
        return $this->createForm($category, $tournament, $eligibilityService);
    }

    private function createForm(Category $category, Tournament $tournament, PhaseEligibilityService $eligibilityService): View|RedirectResponse
    {
        $this->authorize('create', [CompetitionPhase::class, $category]);

        if ($redirect = $this->guardFirstPhase($category, $tournament)) {
            return $redirect;
        }

        $typeOptions = $eligibilityService->firstPhaseTypeOptions($category, $tournament);
        $eligibleTeamCount = $eligibilityService->eligibleTeams($category, $tournament)->count();

        return view('pages.phases.create', compact('category', 'tournament', 'typeOptions', 'eligibleTeamCount'));
    }

    public function store(CompetitionPhaseRequest $request, Category $category, PhaseEligibilityService $eligibilityService, KnockoutBracketService $bracketService): RedirectResponse
    {
        // See create()'s comment: authorize before resolveSoleTournament()
        // can abort with a 404 ahead of the policy's own 403.
        $this->authorize('create', [CompetitionPhase::class, $category]);

        return $this->storePhase($request, $category, $category->resolveSoleTournament(), $eligibilityService, $bracketService);
    }

    /**
     * Same as store(), but for a category inscribed in more than one
     * tournament -- see createForTournament().
     */
    public function storeForTournament(CompetitionPhaseRequest $request, Tournament $tournament, Category $category, PhaseEligibilityService $eligibilityService, KnockoutBracketService $bracketService): RedirectResponse
    {
        return $this->storePhase($request, $category, $tournament, $eligibilityService, $bracketService);
    }

    private function storePhase(CompetitionPhaseRequest $request, Category $category, Tournament $tournament, PhaseEligibilityService $eligibilityService, KnockoutBracketService $bracketService): RedirectResponse
    {
        $this->authorize('create', [CompetitionPhase::class, $category]);

        if ($redirect = $this->guardFirstPhase($category, $tournament)) {
            return $redirect;
        }

        $type = CompetitionPhaseType::from($request->validated('type'));

        if (! $eligibilityService->firstPhaseTypeAllowed($category, $tournament, $type)) {
            throw ValidationException::withMessages([
                'type' => __('Ese tipo de fase no está disponible todavía para esta categoría.'),
            ]);
        }

        $phase = DB::transaction(function () use ($category, $tournament, $request, $type, $bracketService, $eligibilityService): CompetitionPhase {
            $phase = new CompetitionPhase;
            $phase->tournament_id = $tournament->id;
            $phase->category_id = $category->id;
            $phase->name = (string) $request->validated('name');
            $phase->type = $type;
            $phase->knockout_format = $type === CompetitionPhaseType::League
                ? null
                : ScheduleFormat::from($request->validated('knockout_format') ?? ScheduleFormat::SingleRound->value);
            $phase->plays_third_place = $type === CompetitionPhaseType::League
                ? false
                : $request->boolean('plays_third_place');
            $phase->final_knockout_format = $type === CompetitionPhaseType::League || ! $request->filled('final_knockout_format')
                ? null
                : ScheduleFormat::from($request->validated('final_knockout_format'));
            // Only ever one first phase per category: everything after it is
            // chained from a finished phase's qualifiers via the advancement
            // flow, which is what assigns every later phase's order.
            $phase->order = 1;
            $phase->save();

            if ($type !== CompetitionPhaseType::League) {
                // No standings to seed by yet -- a category's first phase is
                // drawn straight from its (tournament-scoped) team list, so
                // this is always random.
                $teams = $eligibilityService->eligibleTeams($category, $tournament)->shuffle();
                $bracketService->generateBracket($phase, $teams);
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
     * A category may only ever get its first phase created directly per
     * TOURNAMENT (there's no standings table yet to draw qualifiers from);
     * every phase after it is created from an already-finished phase via
     * the advancement flow. Scoped by tournament (T02-03) so a catalog
     * category inscribed into more than one tournament gets its own
     * independent phase chain in each.
     */
    private function guardFirstPhase(Category $category, Tournament $tournament): ?RedirectResponse
    {
        if ($category->competitionPhases()->where('tournament_id', $tournament->id)->exists()) {
            return to_route('tournaments.categories.show', [$tournament, $category])->with('error', __(
                'Esta categoría ya tiene una fase inicial. Para crear la siguiente, marca su fase de liga como finalizada y define los clasificados desde ahí.'
            ));
        }

        return null;
    }

    public function show(
        Request $request,
        CompetitionPhase $phase,
        StandingsService $standingsService,
        PhaseEligibilityService $eligibilityService,
        CompetitionStatisticsService $statisticsService,
        PhaseBoardService $boardService,
        MatchProgrammingReportService $programming,
    ): View {
        $this->authorize('view', $phase);

        $category = $phase->category;
        $category->load('groups');

        $schedules = $boardService->scheduleViews($phase);

        $bracketRounds = $phase->type !== CompetitionPhaseType::League
            ? $boardService->bracketRounds($phase)
            : [];

        $bracketColumns = $boardService->bracketColumns($bracketRounds);

        $bracketSize = $boardService->bracketSizeTokens(count($bracketRounds));

        $thirdPlaceMatch = $phase->type !== CompetitionPhaseType::League
            ? $boardService->thirdPlaceMatch($phase)
            : null;

        $standings = $phase->type === CompetitionPhaseType::League
            ? $standingsService->tablesForPhase($phase)
            : [];

        // A knockout-type phase's champion comes from its bracket's final
        // once played; a league-type phase's only ever comes from directly
        // declaring one (see PhaseChampionController), never from a match.
        $champion = $phase->type === CompetitionPhaseType::League
            ? $phase->champion
            : $boardService->championFromBracket($bracketRounds);

        $tableCount = collect($standings)->filter(fn (array $table): bool => count($table['rows']) > 0)->count();

        $isAlreadyResolved = $eligibilityService->isAlreadyResolved($phase);

        $readyToAdvance = $phase->type === CompetitionPhaseType::League
            && $phase->allMatchesFinished()
            && ! $isAlreadyResolved;

        $canDeclareChampion = $eligibilityService->canDeclareChampion($phase, $tableCount);

        // Lets the page offer direct "previous/next phase" navigation
        // instead of forcing a detour back through the category page for
        // every step between phases.
        $previousPhase = $eligibilityService->previousPhase($phase);
        $nextPhase = $eligibilityService->nextPhase($phase);

        $drawReveal = null;

        // The flash is either an int (the league phase the qualifiers came
        // from, so its standings tables can be shown alongside the reveal)
        // or plain `true` (a category's first phase, drawn straight from its
        // team list -- there's no standings table to show for that).
        if ($rawDrawReveal = session('drawReveal')) {
            $sourcePhase = is_int($rawDrawReveal) ? CompetitionPhase::find($rawDrawReveal) : null;

            $drawReveal = [
                // Only each cross's first leg: a two-legged round 1 also has
                // a second leg per cross (same two teams, sides swapped),
                // which would otherwise show every cross twice.
                'matches' => $phase->matches()->where('round_number', 1)->whereNull('first_leg_match_id')->with(['homeTeam', 'awayTeam'])->orderBy('id')->get(),
                'tables' => $sourcePhase ? $standingsService->tablesForPhase($sourcePhase) : [],
            ];
        }

        // Player statistics (goleadores/asistidores/amarillas/rojas) are
        // reached from this same tab bar (see section-tabs in the view), but
        // -- unlike the table/calendar it sits beside -- always reflect the
        // whole category, not just this one phase: "toda la competición"
        // wouldn't mean anything scoped to a single phase. Only offered on a
        // league-type phase's page, matching where the tab bar itself
        // already lives.
        $statistics = $phase->type === CompetitionPhaseType::League
            ? $boardService->statisticsPanels($request, $category, $statisticsService)
            : null;

        // Fechas of this category (in this tournament) with matches still to
        // be played -- the calendar's export menu offers a programming sheet
        // for each one.
        $programmingRounds = $phase->type === CompetitionPhaseType::League
            ? $programming->pendingRounds($phase->tournament, $category)
            : [];

        return view('pages.phases.show', compact(
            'phase', 'category', 'schedules', 'bracketRounds', 'bracketColumns', 'bracketSize', 'thirdPlaceMatch',
            'champion', 'standings', 'readyToAdvance', 'isAlreadyResolved', 'canDeclareChampion', 'drawReveal', 'statistics',
            'previousPhase', 'nextPhase', 'programmingRounds'
        ));
    }

    public function edit(CompetitionPhase $phase, PhaseEligibilityService $eligibilityService): View
    {
        $this->authorize('update', $phase);

        $typeIsLocked = $phase->matches()->exists();
        $typeOptions = $this->isFirstPhase($phase) ? $eligibilityService->firstPhaseTypeOptions($phase->category, $phase->tournament) : null;
        $eligibleTeamCount = $eligibilityService->eligibleTeams($phase->category, $phase->tournament)->count();

        return view('pages.phases.edit', compact('phase', 'typeIsLocked', 'typeOptions', 'eligibleTeamCount'));
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
        if ($this->isFirstPhase($phase) && ! $eligibilityService->firstPhaseTypeAllowed($phase->category, $phase->tournament, $type)) {
            throw ValidationException::withMessages([
                'type' => __('Ese tipo de fase no está disponible todavía para esta categoría.'),
            ]);
        }

        $phase->update($request->validated());
        // Explicitly nulled/reset rather than left to validated()'s merge: a
        // 'prohibited' field absent from the request simply isn't present
        // in validated() at all, which would otherwise leave a stale format
        // behind when switching a still-matchless phase back to League.
        $phase->knockout_format = $type === CompetitionPhaseType::League
            ? null
            : ScheduleFormat::from($request->validated('knockout_format') ?? ScheduleFormat::SingleRound->value);
        $phase->plays_third_place = $type === CompetitionPhaseType::League
            ? false
            : $request->boolean('plays_third_place');
        $phase->final_knockout_format = $type === CompetitionPhaseType::League || ! $request->filled('final_knockout_format')
            ? null
            : ScheduleFormat::from($request->validated('final_knockout_format'));
        $phase->save();

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

        $tournament = $phase->tournament;
        $category = $phase->category;

        $phase->delete();

        return to_route('tournaments.categories.show', [$tournament, $category]);
    }
}
