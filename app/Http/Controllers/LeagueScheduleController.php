<?php

namespace App\Http\Controllers;

use App\Enums\MatchStatus;
use App\Enums\ScheduleFormat;
use App\Http\Requests\GenerateLeagueScheduleRequest;
use App\Models\CompetitionPhase;
use App\Models\Group;
use App\Models\LeagueSchedule;
use App\Models\TournamentMatch;
use App\Services\LeagueScheduleService;
use App\Services\PhaseEligibilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class LeagueScheduleController extends Controller
{
    public function store(GenerateLeagueScheduleRequest $request, CompetitionPhase $phase, LeagueScheduleService $service): RedirectResponse
    {
        $this->authorize('create', [TournamentMatch::class, $phase]);

        $category = $phase->category;
        $format = ScheduleFormat::from($request->validated('format'));

        $roster = $phase->teams;
        $scopes = $roster->isEmpty() && $category->uses_groups ? $category->groups : collect([null]);

        DB::transaction(function () use ($scopes, $category, $phase, $format, $service, $roster): void {
            foreach ($scopes as $group) {
                /** @var Group|null $group */
                $teams = $group ? $group->teams : ($roster->isNotEmpty() ? $roster : $category->teams);

                $schedule = new LeagueSchedule;
                $schedule->tournament_id = $phase->tournament_id;
                $schedule->competition_phase_id = $phase->id;
                $schedule->group_id = $group?->id;
                $schedule->format = $format;
                $schedule->generated_at = now();
                $schedule->save();

                foreach ($service->generate($teams, $format) as $round) {
                    foreach ($round['fixtures'] as $fixture) {
                        $match = new TournamentMatch;
                        $match->tournament_id = $phase->tournament_id;
                        $match->category_id = $category->id;
                        $match->competition_phase_id = $phase->id;
                        $match->group_id = $group?->id;
                        $match->league_schedule_id = $schedule->id;
                        $match->home_team_id = $fixture['home_team_id'];
                        $match->away_team_id = $fixture['away_team_id'];
                        $match->round_number = $round['round_number'];
                        $match->status = MatchStatus::Scheduled;
                        $match->save();
                    }
                }
            }
        });

        return to_route('phases.show', $phase)->with('status', __('Calendario generado correctamente.'));
    }

    public function destroy(CompetitionPhase $phase, PhaseEligibilityService $eligibilityService): RedirectResponse
    {
        $this->authorize('update', $phase);

        // This schedule's final standings are the sustento of whatever was
        // built from them (a declared champion, or a next phase's roster) --
        // deleting it out from under that would leave a stale, unsupported
        // result. Undoing that outcome (removing the champion, or deleting
        // the next phase) is what has to happen first.
        if ($eligibilityService->isAlreadyResolved($phase)) {
            return to_route('phases.show', $phase)->with('error', __(
                'No se puede eliminar el calendario: ya se declaró un campeón o se creó una fase siguiente a partir de esta tabla. Elimina esa fase (o quita el campeón declarado) primero.'
            ));
        }

        DB::transaction(function () use ($phase): void {
            $scheduleIds = $phase->leagueSchedules()->pluck('id');

            TournamentMatch::query()->whereIn('league_schedule_id', $scheduleIds)->delete();
            LeagueSchedule::query()->whereIn('id', $scheduleIds)->delete();
        });

        return to_route('phases.show', $phase)->with('status', __('Calendario eliminado correctamente.'));
    }
}
