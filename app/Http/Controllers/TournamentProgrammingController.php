<?php

namespace App\Http\Controllers;

use App\Enums\MatchStatus;
use App\Http\Requests\MatchProgrammingStoreRequest;
use App\Models\Category;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Services\MatchProgrammingPlannerService;
use App\Services\MatchProgrammingReportService;
use App\Services\MatchSchedulingConflictService;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * "Programar jornada": assigns days, hours and canchas to the pending league
 * matches of ONE category in ONE jornada of a tournament. Pick the jornada, pick
 * the category, then fill day / cancha / first hour / rest: the proposed slot
 * of every match of that category refreshes on its own as the fields change
 * (preview(), fetched by the page), and saving applies it (store()). Meant for
 * calendars that were generated without dates.
 */
class TournamentProgrammingController extends Controller
{
    public function edit(Request $request, Tournament $tournament, MatchProgrammingReportService $report): View
    {
        $this->authorize('update', $tournament);

        // Every jornada with its categories and pending matches, sent once: the page
        // keeps it in the browser and filters it there (a few dozen KB even for
        // hundreds of matches), so picking a jornada or a category never reloads.
        $catalog = $report->programmingCatalog($tournament);

        $round = collect($catalog)->firstWhere('number', $request->integer('round'));
        $category = $round ? collect($round['categories'])->firstWhere('id', $request->integer('category')) : null;

        return view('pages.tournaments.programming.edit', [
            'tournament' => $tournament,
            'catalog' => $catalog,
            'venues' => Auth::user()->venues()->orderBy('name')->get(),
            'referees' => Auth::user()->referees()->orderBy('full_name')->get(),
            // What the page starts on: from the URL, and after a failed save the
            // fields come back as they were typed.
            'initial' => [
                'round' => $round['number'] ?? null,
                'category' => $category['id'] ?? null,
                'overwrite' => $request->boolean('overwrite'),
            ],
            'config' => (array) old('config', []),
        ]);
    }

    /**
     * The proposal panel only (an HTML fragment), re-fetched by the page every
     * time day / cancha / first hour / rest change. It's deliberately lenient:
     * a half-typed field (a time with no day yet) just means "nothing to
     * propose yet", never an error.
     */
    public function preview(Request $request, Tournament $tournament, MatchProgrammingPlannerService $planner, MatchSchedulingConflictService $conflicts): View
    {
        $this->authorize('update', $tournament);

        $round = $request->integer('round');
        $category = Category::query()->find($request->integer('category'));
        $matches = $category !== null
            ? $planner->candidates($tournament, $round, $request->boolean('overwrite'), $category)
            : new Collection;

        // Matches the organizer marked "no programar" (a team can't play): they take
        // no slot, so the rest are planned as if they weren't in the jornada.
        $skipped = $this->skippedIds($request);

        // "config": the four fields changed, so the proposal is calculated again.
        // "matches": one match's day/time/cancha was adjusted by hand -- keep every
        // value as it is and only re-check the clashes.
        $proposal = $request->input('mode') === 'matches' ? $this->proposalFromMatches($request, $matches, $skipped) : null;

        return view('pages.tournaments.programming._preview', [
            'preview' => $this->previewData($planner, $conflicts, $matches, $this->lenientConfig((array) $request->input('config', [])), $skipped, $proposal),
            'venues' => Auth::user()->venues()->orderBy('name')->get(),
            'referees' => Auth::user()->referees()->orderBy('full_name')->get(),
        ]);
    }

    public function store(MatchProgrammingStoreRequest $request, Tournament $tournament, MatchProgrammingPlannerService $planner, MatchSchedulingConflictService $conflicts): RedirectResponse
    {
        $this->authorize('update', $tournament);

        $round = (int) $request->validated('round');
        $category = $request->validated('category');
        $back = ['round' => $round, 'category' => $category];
        $proposed = $request->proposed();

        // Re-read from the database instead of trusting the ids posted: only
        // this tournament's pending league matches of this jornada count.
        $matches = $planner->candidates($tournament, $round, overwrite: true)->whereIn('id', array_keys($proposed));
        $proposed = array_intersect_key($proposed, $matches->keyBy('id')->all());

        if ($proposed === []) {
            return to_route('tournaments.programming.edit', [$tournament, ...$back])
                ->with('error', __('No hay partidos para programar con esos datos.'));
        }

        // Only slots with a day can clash; a cancha / referee alone never does.
        $dated = array_filter($proposed, fn (array $slot): bool => $slot['at'] !== null);
        $found = $dated === [] ? [] : $conflicts->conflictsForBatch($matches->whereIn('id', array_keys($dated)), $dated);

        if ($found !== []) {
            $messages = [];

            foreach ($matches->keyBy('id') as $id => $match) {
                foreach ($found[$id] ?? [] as $conflict) {
                    $messages[] = $match->homeTeam->name.' vs '.$match->awayTeam->name.': '.$conflict;
                }
            }

            return to_route('tournaments.programming.edit', [$tournament, ...$back])
                ->withInput($request->only('config'))
                ->withErrors(['schedule' => $messages]);
        }

        DB::transaction(function () use ($matches, $proposed): void {
            foreach ($matches as $match) {
                $slot = $proposed[$match->id];

                // No day given: only the cancha / referee chosen are applied, the
                // match's day stays as it was.
                if ($slot['at'] === null) {
                    $match->update(array_filter(['venue_id' => $slot['venue_id'], 'referee_id' => $slot['referee_id']], fn ($value): bool => $value !== null));

                    continue;
                }

                $match->update([
                    'scheduled_at' => $slot['at'],
                    'venue_id' => $slot['venue_id'],
                    // Left as it was when none is chosen: an existing referee is never wiped here.
                    'referee_id' => $slot['referee_id'] ?? $match->referee_id,
                    'status' => $match->status === MatchStatus::Postponed ? MatchStatus::Scheduled : $match->status,
                ]);
            }
        });

        return to_route('tournaments.programming.edit', [$tournament, ...$back])
            ->with('status', trans_choice(':count partido programado correctamente.|:count partidos programados correctamente.', count($proposed), ['count' => count($proposed)]));
    }

    /**
     * The slots exactly as they sit in the panel's fields (only for matches of this
     * category; a blank or invalid day, or a skipped match, means "no slot for that match").
     *
     * @param  Collection<int, TournamentMatch>  $matches
     * @return array<int, array{at: CarbonInterface|null, venue_id: int|null, referee_id: int|null}>
     */
    private function proposalFromMatches(Request $request, Collection $matches, array $skipped): array
    {
        $allowed = $matches->keyBy('id');
        $proposed = [];

        foreach ((array) $request->input('matches', []) as $matchId => $slot) {
            if (! $allowed->has((int) $matchId) || ! is_array($slot) || in_array((int) $matchId, $skipped, true)) {
                continue;
            }

            $config = $this->lenientConfig([
                'date' => $slot['date'] ?? null,
                'start' => $slot['time'] ?? null,
                'venue_id' => $slot['venue_id'] ?? null,
                'referee_id' => $slot['referee_id'] ?? null,
            ]);

            if ($config['date'] === null && $config['venue_id'] === null && $config['referee_id'] === null) {
                continue;
            }

            $proposed[(int) $matchId] = [
                'at' => $config['date'] !== null ? TournamentMatch::composeScheduledAt($config['date'], $config['start']) : null,
                'venue_id' => $config['venue_id'],
                'referee_id' => $config['referee_id'],
            ];
        }

        return $proposed;
    }

    /**
     * Ids of the matches ticked "no programar" in the panel (matches[id][skip]).
     *
     * @return list<int>
     */
    private function skippedIds(Request $request): array
    {
        return collect((array) $request->input('matches', []))
            ->filter(fn ($slot): bool => is_array($slot) && filter_var($slot['skip'] ?? false, FILTER_VALIDATE_BOOLEAN))
            ->keys()
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Keeps only what can be a valid value, so a half-typed form still gives a
     * (possibly empty) proposal instead of an error.
     *
     * @param  array<string, mixed>  $input
     * @return array{date: string|null, venue_id: int|null, referee_id: int|null, start: string|null, rest: int}
     */
    private function lenientConfig(array $input): array
    {
        $date = $input['date'] ?? null;
        $validDate = is_string($date) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts) === 1 && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);

        $start = $input['start'] ?? null;
        $validStart = is_string($start) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $start) === 1;

        $venueId = filled($input['venue_id'] ?? null) ? (int) $input['venue_id'] : null;

        if ($venueId !== null && ! Auth::user()->venues()->whereKey($venueId)->exists()) {
            $venueId = null;
        }

        $refereeId = filled($input['referee_id'] ?? null) ? (int) $input['referee_id'] : null;

        if ($refereeId !== null && ! Auth::user()->referees()->whereKey($refereeId)->exists()) {
            $refereeId = null;
        }

        return [
            'date' => $validDate ? $date : null,
            'venue_id' => $venueId,
            'referee_id' => $refereeId,
            'start' => $validDate && $validStart ? $start : null,
            'rest' => filled($input['rest'] ?? null) ? max(0, min(120, (int) $input['rest'])) : 0,
        ];
    }

    /**
     * Every match of the category in play order, with its proposed slot (blank
     * until a day is given) and whatever clashes that slot has.
     *
     * @param  Collection<int, TournamentMatch>  $matches
     * @param  array{date: string|null, venue_id: int|null, referee_id: int|null, start: string|null, rest: int}  $config
     * @param  list<int>  $skipped  Matches marked "no programar": they get no slot and don't take part in the planning.
     * @param  array<int, array{at: CarbonInterface|null, venue_id: int|null, referee_id: int|null}>|null  $proposal  Slots to use as they are instead of planning from $config.
     * @return array{items: list<array{match: TournamentMatch, group: string|null, date: string, time: string, venue_id: int|null, referee_id: int|null, skip: bool, conflicts: list<string>}>, conflictCount: int, hasProposal: bool, freeFrom: array{time: string, label: string}|null}
     */
    private function previewData(MatchProgrammingPlannerService $planner, MatchSchedulingConflictService $conflicts, Collection $matches, array $config, array $skipped = [], ?array $proposal = null): array
    {
        $proposed = $proposal ?? $planner->planCategory($matches->reject(fn (TournamentMatch $match): bool => in_array($match->id, $skipped, true)), $config);
        $dated = array_filter($proposed, fn (array $slot): bool => $slot['at'] !== null);
        $found = $dated === [] ? [] : $conflicts->conflictsForBatch($matches->whereIn('id', array_keys($dated)), $dated);

        $items = collect($planner->rows($matches))
            ->flatMap(fn (array $row) => $row['matches']->map(function (TournamentMatch $match) use ($row, $proposed, $proposal, $skipped, $found): array {
                $skip = in_array($match->id, $skipped, true);
                $slot = $proposed[$match->id] ?? null;

                // What the match already has (a day, a time, a cancha, a referee) fills in
                // whatever the fields above didn't decide. Not while the panel is being
                // hand-edited (a value the user just cleared stays cleared) and never on a
                // match left out with "No programar".
                $existing = ! $skip && $proposal === null;
                $at = $slot['at'] ?? ($existing ? $match->scheduled_at : null);

                return [
                    'match' => $match,
                    'group' => $row['group'],
                    'date' => $at?->format('Y-m-d') ?? '',
                    'time' => $at !== null && $at->format('H:i') !== TournamentMatch::NO_KICKOFF_TIME ? $at->format('H:i') : '',
                    'venue_id' => $slot['venue_id'] ?? ($existing ? $match->venue_id : null),
                    'referee_id' => $slot['referee_id'] ?? ($existing ? $match->referee_id : null),
                    'skip' => $skip,
                    'conflicts' => $found[$match->id] ?? [],
                ];
            }))
            ->values()
            ->all();

        // A cancha that already has matches that day: say from when it's free, so
        // the next category can start right after (the field offers to use it).
        $freeFrom = $config['venue_id'] !== null && $config['date'] !== null
            ? $conflicts->venueFreeFrom($config['venue_id'], Carbon::parse($config['date']), $matches->pluck('id')->all())
            : null;

        return [
            'items' => $items,
            'conflictCount' => count($found),
            'hasProposal' => $proposed !== [],
            'freeFrom' => $freeFrom !== null && ($config['start'] === null || $config['start'] < $freeFrom->format('H:i'))
                ? ['time' => $freeFrom->format('H:i'), 'label' => $freeFrom->format('g:i A')]
                : null,
        ];
    }
}
