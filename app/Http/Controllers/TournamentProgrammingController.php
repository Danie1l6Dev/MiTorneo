<?php

namespace App\Http\Controllers;

use App\Enums\MatchStatus;
use App\Http\Requests\MatchProgrammingPreviewRequest;
use App\Http\Requests\MatchProgrammingStoreRequest;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Services\MatchProgrammingPlannerService;
use App\Services\MatchProgrammingReportService;
use App\Services\MatchSchedulingConflictService;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * "Programar fecha": assigns days, hours and canchas to the pending league
 * matches of ONE fecha across every category of a tournament, in three steps --
 * pick the day/cancha/first kickoff per category (edit), review the proposed
 * slot of every match with its clashes (preview), then save (store). Meant for
 * calendars that were generated without dates.
 */
class TournamentProgrammingController extends Controller
{
    public function edit(Request $request, Tournament $tournament, MatchProgrammingReportService $report, MatchProgrammingPlannerService $planner): View
    {
        $this->authorize('update', $tournament);

        $rounds = $report->pendingRounds($tournament);
        $round = in_array($request->integer('round'), $rounds, true) ? $request->integer('round') : null;
        $overwrite = $request->boolean('overwrite', false);

        $rows = $round !== null ? $planner->rows($planner->candidates($tournament, $round, $overwrite)) : [];

        // A round can exist (it has pending matches) yet have nothing left to
        // assign without "overwrite" -- because every match already has a day.
        $allScheduled = $round !== null && $rows === [];

        return view('pages.tournaments.programming.edit', [
            'tournament' => $tournament,
            'rounds' => $rounds,
            'round' => $round,
            'rows' => $rows,
            'allScheduled' => $allScheduled,
            'overwrite' => $overwrite,
            'venues' => Auth::user()->venues()->orderBy('name')->get(),
            'roundTitle' => $round !== null ? $report->roundTitle($round) : null,
        ]);
    }

    public function preview(MatchProgrammingPreviewRequest $request, Tournament $tournament, MatchProgrammingPlannerService $planner, MatchSchedulingConflictService $conflicts): View|RedirectResponse
    {
        $this->authorize('update', $tournament);

        $round = (int) $request->validated('round');
        $matches = $planner->candidates($tournament, $round, (bool) $request->validated('overwrite'));
        $proposed = $planner->plan($planner->rows($matches), $request->rowsConfig());

        if ($proposed === []) {
            return to_route('tournaments.programming.edit', [$tournament, 'round' => $round])
                ->with('error', __('No hay partidos para programar con esos datos.'));
        }

        return $this->previewView($tournament, $round, (bool) $request->validated('overwrite'), $matches->whereIn('id', array_keys($proposed)), $proposed, $conflicts);
    }

    public function store(MatchProgrammingStoreRequest $request, Tournament $tournament, MatchProgrammingPlannerService $planner, MatchSchedulingConflictService $conflicts): View|RedirectResponse
    {
        $this->authorize('update', $tournament);

        $round = (int) $request->validated('round');
        $proposed = $request->proposed();

        // Re-read from the database instead of trusting the ids posted: only
        // this tournament's pending league matches of this fecha count.
        $matches = $planner->candidates($tournament, $round, overwrite: true)->whereIn('id', array_keys($proposed));
        $proposed = array_intersect_key($proposed, $matches->keyBy('id')->all());

        if ($proposed === []) {
            return to_route('tournaments.programming.edit', [$tournament, 'round' => $round])
                ->with('error', __('No hay partidos para programar con esos datos.'));
        }

        $found = $conflicts->conflictsForBatch($matches, $proposed);

        if ($found !== []) {
            return $this->previewView($tournament, $round, (bool) $request->boolean('overwrite'), $matches, $proposed, $conflicts, $found);
        }

        DB::transaction(function () use ($matches, $proposed): void {
            foreach ($matches as $match) {
                $slot = $proposed[$match->id];

                $match->update([
                    'scheduled_at' => $slot['at'],
                    'venue_id' => $slot['venue_id'],
                    'status' => $match->status === MatchStatus::Postponed ? MatchStatus::Scheduled : $match->status,
                ]);
            }
        });

        return to_route('tournaments.programming.edit', [$tournament, 'round' => $round])
            ->with('status', trans_choice(':count partido programado correctamente.|:count partidos programados correctamente.', count($proposed), ['count' => count($proposed)]));
    }

    /**
     * The review screen: every proposed match in an editable row, grouped by
     * category/group, with whatever clashes the scheduler found (computed here
     * unless the caller already has them).
     *
     * @param  Collection<int, TournamentMatch>  $matches
     * @param  array<int, array{at: CarbonInterface, venue_id: int|null}>  $proposed
     * @param  array<int, list<string>>|null  $found
     */
    private function previewView(Tournament $tournament, int $round, bool $overwrite, $matches, array $proposed, MatchSchedulingConflictService $conflicts, ?array $found = null): View
    {
        $found ??= $conflicts->conflictsForBatch($matches, $proposed);

        $groups = collect(app(MatchProgrammingPlannerService::class)->rows($matches->values()))
            ->map(fn (array $row): array => [
                'category' => $row['category'],
                'group' => $row['group'],
                'items' => $row['matches']->map(fn (TournamentMatch $match): array => [
                    'match' => $match,
                    'date' => $proposed[$match->id]['at']->format('Y-m-d'),
                    'time' => $proposed[$match->id]['at']->format('H:i') === TournamentMatch::NO_KICKOFF_TIME ? '' : $proposed[$match->id]['at']->format('H:i'),
                    'venue_id' => $proposed[$match->id]['venue_id'],
                    'conflicts' => $found[$match->id] ?? [],
                ])->all(),
            ])
            ->all();

        return view('pages.tournaments.programming.preview', [
            'tournament' => $tournament,
            'round' => $round,
            'roundTitle' => app(MatchProgrammingReportService::class)->roundTitle($round),
            'overwrite' => $overwrite,
            'groups' => $groups,
            'venues' => Auth::user()->venues()->orderBy('name')->get(),
            'conflictCount' => count($found),
        ]);
    }
}
