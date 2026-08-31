<?php

namespace App\Http\Controllers;

use App\Enums\CompetitionPhaseType;
use App\Enums\ScheduleFormat;
use App\Http\Requests\CompetitionPhaseRequest;
use App\Models\Category;
use App\Models\CompetitionPhase;
use App\Models\Group;
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
            ->map(fn (LeagueSchedule $schedule): array => $this->buildScheduleView($schedule, $category));

        $unscheduledMatches = $phase->matches()
            ->with(['homeTeam', 'awayTeam'])
            ->whereNull('league_schedule_id')
            ->get();

        $standings = $phase->type === CompetitionPhaseType::League
            ? $this->buildStandings($phase, $category, $standingsService)
            : [];

        return view('pages.phases.show', compact('phase', 'category', 'schedules', 'unscheduledMatches', 'standings'));
    }

    /**
     * Calculate the standings table(s) for this phase: one per group when the
     * category uses groups, otherwise a single table for the whole category.
     * The table is always derived fresh from the phase's finished matches.
     *
     * @return array<int, array{label: string, rows: array<int, array{team: Team, played: int, won: int, drawn: int, lost: int, goals_for: int, goals_against: int, goal_difference: int, points: int}>}>
     */
    private function buildStandings(CompetitionPhase $phase, Category $category, StandingsService $standingsService): array
    {
        $matches = $phase->matches;

        if ($category->uses_groups) {
            return $category->groups->map(fn (Group $group): array => [
                'label' => $group->name,
                'rows' => $standingsService->calculate($group->teams, $matches->where('group_id', $group->id)),
            ])->values()->all();
        }

        return [[
            'label' => $category->name,
            'rows' => $standingsService->calculate($category->teams, $matches),
        ]];
    }

    /**
     * @return array{schedule: LeagueSchedule, rounds: array<int, array{round_number: int, leg: int, matches: Collection<int, TournamentMatch>, resting_team: Team|null}>}
     */
    private function buildScheduleView(LeagueSchedule $schedule, Category $category): array
    {
        $teams = $schedule->group ? $schedule->group->teams : $category->teams;

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

        return ['schedule' => $schedule, 'rounds' => $rounds];
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
