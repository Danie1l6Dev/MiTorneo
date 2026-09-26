<?php

namespace App\Services;

use App\Enums\CompetitionPhaseType;
use App\Enums\MatchEventType;
use App\Enums\MatchStatus;
use App\Enums\ScheduleFormat;
use App\Models\Coach;
use App\Models\CompetitionPhase;
use App\Models\MatchEvent;
use App\Models\Player;
use App\Models\Team;
use App\Models\TournamentMatch;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Read-only view-model building for the match results PDFs (see
 * MatchResultsPdfController): a single match's full report, or any set of
 * matches (a jornada, a knockout round, a whole phase, a whole category)
 * laid out as tables. Jornadas/rounds are built on top of PhaseBoardService
 * so the PDF groups and labels matches exactly like the phase page does.
 *
 * Every optional piece of data (date, referee, events, jersey numbers...) is
 * only ever included when the match actually has it recorded -- the views
 * never print an empty "Árbitro:" label.
 */
class MatchResultsReportService
{
    public function __construct(private PhaseBoardService $board) {}

    /**
     * $phase's matches split into sections (one per jornada for a league
     * phase, one per round for a knockout one), each holding one or more
     * blocks (one per group, or the 3er/4to puesto match on its own).
     * $roundNumber narrows it down to that one jornada/round;
     * $onlyPlayed drops every match that doesn't have a finished result.
     * Sections/blocks left without any match are dropped.
     *
     * @return Collection<int, array{title: string, subtitle: string|null, blocks: list<array{label: string|null, rows: list<array<string, mixed>>, resting: string|null}>}>
     */
    public function phaseSections(CompetitionPhase $phase, ?int $roundNumber = null, bool $onlyPlayed = false): Collection
    {
        $sections = $phase->type === CompetitionPhaseType::League
            ? $this->leagueSections($phase, $roundNumber)
            : $this->knockoutSections($phase, $roundNumber);

        $this->loadReportRelations($sections->flatMap(
            fn (array $section): Collection => collect($section['blocks'])->flatMap(fn (array $block): array => array_column($block['matches'], 'match'))
        ));

        return $sections
            ->map(function (array $section) use ($onlyPlayed): array {
                $section['blocks'] = collect($section['blocks'])
                    ->map(function (array $block) use ($onlyPlayed): array {
                        $entries = collect($block['matches'])
                            ->filter(fn (array $entry): bool => ! $onlyPlayed || $entry['match']->status === MatchStatus::Finished);

                        return [
                            'label' => $block['label'],
                            'rows' => $entries->map(fn (array $entry): array => $this->row($entry['match'], $entry['note']))->values()->all(),
                            'resting' => $block['resting'],
                        ];
                    })
                    ->filter(fn (array $block): bool => $block['rows'] !== [])
                    ->values()
                    ->all();

                return $section;
            })
            ->filter(fn (array $section): bool => $section['blocks'] !== [])
            ->values();
    }

    /**
     * @return Collection<int, array{title: string, subtitle: string|null, blocks: list<array{label: string|null, matches: list<array{match: TournamentMatch, note: string|null}>, resting: string|null}>}>
     */
    private function leagueSections(CompetitionPhase $phase, ?int $roundNumber): Collection
    {
        $scheduleViews = $this->board->scheduleViews($phase);
        $showGroupLabels = $scheduleViews->count() > 1;
        $sections = [];

        foreach ($scheduleViews as $view) {
            $schedule = $view['schedule'];

            foreach ($view['rounds'] as $round) {
                if ($roundNumber !== null && $round['round_number'] !== $roundNumber) {
                    continue;
                }

                $number = $round['round_number'];

                $sections[$number] ??= [
                    'title' => __('Jornada :number', ['number' => $number]),
                    'subtitle' => $schedule->format === ScheduleFormat::HomeAndAway
                        ? ($round['leg'] === 1 ? __('Primera vuelta') : __('Segunda vuelta'))
                        : null,
                    'blocks' => [],
                ];

                $sections[$number]['blocks'][] = [
                    'label' => $showGroupLabels ? ($schedule->group?->name ?? $phase->category->name) : null,
                    'matches' => $round['matches']->map(fn (TournamentMatch $match): array => ['match' => $match, 'note' => null])->all(),
                    'resting' => $round['resting_team']?->name,
                ];
            }
        }

        ksort($sections);

        return collect(array_values($sections));
    }

    /**
     * @return Collection<int, array{title: string, subtitle: string|null, blocks: list<array{label: string|null, matches: list<array{match: TournamentMatch, note: string|null}>, resting: string|null}>}>
     */
    private function knockoutSections(CompetitionPhase $phase, ?int $roundNumber): Collection
    {
        $rounds = $this->board->bracketRounds($phase);
        $thirdPlaceMatch = $this->board->thirdPlaceMatch($phase);
        $finalRoundNumber = empty($rounds) ? null : end($rounds)['round_number'];

        return collect($rounds)
            ->filter(fn (array $round): bool => $roundNumber === null || $round['round_number'] === $roundNumber)
            ->map(function (array $round) use ($thirdPlaceMatch, $finalRoundNumber): array {
                $matches = $round['matches']->flatMap(fn (Collection $cross): array => $cross->count() > 1
                    ? [
                        ['match' => $cross->first(), 'note' => __('Ida')],
                        ['match' => $cross->last(), 'note' => __('Vuelta')],
                    ]
                    : [['match' => $cross->first(), 'note' => null]]
                )->all();

                $blocks = [['label' => null, 'matches' => $matches, 'resting' => null]];

                if ($thirdPlaceMatch !== null && $round['round_number'] === $finalRoundNumber) {
                    $blocks[] = ['label' => __('3er y 4to puesto'), 'matches' => [['match' => $thirdPlaceMatch, 'note' => null]], 'resting' => null];
                }

                return ['title' => $round['label'], 'subtitle' => null, 'blocks' => $blocks];
            })
            ->values();
    }

    /**
     * @param  Collection<int, TournamentMatch>  $matches
     */
    private function loadReportRelations(Collection $matches): void
    {
        (new EloquentCollection($matches->all()))->load([
            'homeTeam',
            'awayTeam',
            'referee',
            'venue',
            'firstLeg',
            'walkoverTeam',
            'events' => fn ($query) => $query->with(['player', 'coach'])->orderBy('id'),
        ]);
    }

    /**
     * One match's line in a results table.
     *
     * @return array{match: TournamentMatch, note: string|null, score: string, details: list<string>, events: array{home: list<string>, away: list<string>}|null}
     */
    private function row(TournamentMatch $match, ?string $note): array
    {
        return [
            'match' => $match,
            'note' => $note,
            'score' => $this->scoreText($match),
            'details' => $this->resultDetails($match),
            'events' => $this->eventsBySide($match),
        ];
    }

    /**
     * Each side's "NAME (2 G, A)" lines, kept apart so the views can print
     * them under that team's own column -- null when neither side has any.
     *
     * @return array{home: list<string>, away: list<string>}|null
     */
    private function eventsBySide(TournamentMatch $match): ?array
    {
        $events = [
            'home' => $match->home_team_id !== null ? $this->eventSummaries($match->events->where('team_id', $match->home_team_id)) : [],
            'away' => $match->away_team_id !== null ? $this->eventSummaries($match->events->where('team_id', $match->away_team_id)) : [],
        ];

        return $events['home'] === [] && $events['away'] === [] ? null : $events;
    }

    /**
     * "2 - 1" once a score is recorded, otherwise the match's status
     * ("Programado", "Postergado"...).
     */
    public function scoreText(TournamentMatch $match): string
    {
        if ($match->home_score !== null && $match->away_score !== null) {
            return "{$match->home_score} - {$match->away_score}";
        }

        return __($match->status->label());
    }

    /**
     * Extra lines under the score: walkover, extra time, penalties, and the
     * aggregate for a two-legged cross's second leg -- each only when it
     * actually applies to this match.
     *
     * @return list<string>
     */
    public function resultDetails(TournamentMatch $match): array
    {
        $details = [];

        if ($match->is_walkover) {
            $details[] = $match->walkoverTeam
                ? __('Perdido por W (:team)', ['team' => $match->walkoverTeam->name])
                : __('Perdido por W');
        }

        if ($match->home_extra_time_score !== null && $match->away_extra_time_score !== null) {
            $details[] = __('Prórroga :home - :away', ['home' => $match->home_extra_time_score, 'away' => $match->away_extra_time_score]);
        }

        if ($match->home_penalty_score !== null && $match->away_penalty_score !== null) {
            $details[] = __('Penales :home - :away', ['home' => $match->home_penalty_score, 'away' => $match->away_penalty_score]);
        }

        if ($match->first_leg_match_id !== null && ($aggregate = $match->regularTimeAggregate()) !== null) {
            $details[] = __('Global :home - :away', ['home' => $aggregate['home'], 'away' => $aggregate['away']]);
        }

        return $details;
    }

    /**
     * One "NAME (2 G, A)" line per player/coach with events in $events,
     * in the order they were first registered.
     *
     * @param  Collection<int, MatchEvent>  $events
     * @return list<string>
     */
    private function eventSummaries(Collection $events): array
    {
        return $events
            ->groupBy(fn (MatchEvent $event): string => $event->player_id !== null ? 'player-'.$event->player_id : 'coach-'.$event->coach_id)
            ->map(fn (Collection $group): string => $group->first()->subjectLabel().' ('.$this->eventCountsText($group).')')
            ->values()
            ->all();
    }

    /**
     * "2 G, A, TA" -- a count prefix only when it's more than one.
     *
     * @param  Collection<int, MatchEvent>  $events
     */
    private function eventCountsText(Collection $events): string
    {
        return collect(MatchEventType::cases())
            ->map(function (MatchEventType $type) use ($events): ?string {
                $count = $events->where('type', $type)->count();

                return match (true) {
                    $count === 0 => null,
                    $count === 1 => $type->shortLabel(),
                    default => $count.' '.$type->shortLabel(),
                };
            })
            ->filter()
            ->implode(', ');
    }

    /**
     * Everything the single-match report prints.
     *
     * @return array<string, mixed>
     */
    public function matchReport(TournamentMatch $match): array
    {
        $match->load([
            'tournament',
            'category',
            'competitionPhase',
            'group',
            'referee',
            'venue',
            'firstLeg',
            'secondLeg',
            'walkoverTeam',
            'homeTeam.coach',
            'awayTeam.coach',
            'events' => fn ($query) => $query->with(['player', 'coach'])->orderBy('id'),
            'sanctions' => fn ($query) => $query->with(['player', 'coach', 'team'])->orderBy('id'),
        ]);

        $teams = collect(['home' => $match->homeTeam, 'away' => $match->awayTeam])->filter();

        $statistics = $match->events->isEmpty() ? [] : collect(MatchEventType::cases())
            ->map(fn (MatchEventType $type): array => [
                'label' => match ($type) {
                    MatchEventType::Goal => __('Goles'),
                    MatchEventType::Assist => __('Asistencias'),
                    default => __($type->leaderboardTitle()),
                },
                'home' => $match->events->where('team_id', $match->home_team_id)->where('type', $type)->count(),
                'away' => $match->events->where('team_id', $match->away_team_id)->where('type', $type)->count(),
            ])
            ->all();

        return [
            'match' => $match,
            'roundLabel' => $this->roundLabel($match),
            'score' => $this->scoreText($match),
            'details' => $this->resultDetails($match),
            'firstLegScore' => $match->first_leg_match_id !== null && $match->firstLeg->home_score !== null && $match->firstLeg->away_score !== null
                ? "{$match->firstLeg->home_score} - {$match->firstLeg->away_score}"
                : null,
            'statistics' => $statistics,
            'events' => $this->eventsBySide($match),
            'rosters' => $teams->map(fn (Team $team): array => $this->roster($team, $match))->all(),
            'hasEvents' => $match->events->isNotEmpty(),
        ];
    }

    /**
     * "Jornada 3", "Cuartos de final - Ida", "3er y 4to puesto"... or null
     * for a match with no round at all.
     */
    private function roundLabel(TournamentMatch $match): ?string
    {
        $phase = $match->competitionPhase;

        if ($phase->type === CompetitionPhaseType::League) {
            return $match->round_number !== null ? __('Jornada :number', ['number' => $match->round_number]) : null;
        }

        if ($match->is_third_place) {
            return __('3er y 4to puesto');
        }

        $label = collect($this->board->bracketRounds($phase))->firstWhere('round_number', $match->round_number)['label'] ?? null;

        $leg = match (true) {
            $match->first_leg_match_id !== null => __('Vuelta'),
            $match->secondLeg !== null => __('Ida'),
            default => null,
        };

        return collect([$label, $leg])->filter()->implode(' - ') ?: null;
    }

    /**
     * $team's plantel for the match report: its own active roster plus
     * anyone who registered an event for it in this match without being on
     * it (a player playing up from a younger category of the same club),
     * each with their event counts for THIS match. The coach is listed
     * separately, only when the team has one.
     *
     * @return array{team: Team, players: list<array{name: string, jersey: int|null, counts: array<string, int>}>, coach: array{name: string, counts: array<string, int>}|null, hasJerseys: bool}
     */
    private function roster(Team $team, TournamentMatch $match): array
    {
        $teamEvents = $match->events->where('team_id', $team->id);

        $rosterPlayers = $team->players()->where('is_active', true)->get()
            ->map(fn (Player $player): array => ['player' => $player, 'jersey' => $player->jersey_number])
            ->concat($team->globalPlayers()->where('is_active', true)->get()
                ->map(fn (Player $player): array => ['player' => $player, 'jersey' => $player->pivot->jersey_number ?? $player->jersey_number]));

        $eventPlayers = $teamEvents->whereNotNull('player_id')
            ->map(fn (MatchEvent $event): array => ['player' => $event->player, 'jersey' => null]);

        $players = $rosterPlayers->concat($eventPlayers)
            ->unique(fn (array $entry): int => $entry['player']->id)
            // Jersey order, like a real scoresheet; anyone without a
            // number goes after, alphabetically.
            ->sortBy([
                fn (array $a, array $b): int => ($a['jersey'] ?? PHP_INT_MAX) <=> ($b['jersey'] ?? PHP_INT_MAX),
                fn (array $a, array $b): int => $a['player']->full_name <=> $b['player']->full_name,
            ])
            ->map(fn (array $entry): array => [
                'name' => $entry['player']->full_name,
                'jersey' => $entry['jersey'],
                'counts' => $this->countsByType($teamEvents->where('player_id', $entry['player']->id)),
            ])
            ->values()
            ->all();

        /** @var Coach|null $coach */
        $coach = $team->coach ?? $teamEvents->whereNotNull('coach_id')->first()?->coach;

        return [
            'team' => $team,
            'players' => $players,
            'coach' => $coach ? [
                'name' => $coach->full_name,
                'counts' => $this->countsByType($teamEvents->where('coach_id', $coach->id)),
            ] : null,
            'hasJerseys' => collect($players)->contains(fn (array $player): bool => $player['jersey'] !== null),
        ];
    }

    /**
     * @param  Collection<int, MatchEvent>  $events
     * @return array<string, int>
     */
    private function countsByType(Collection $events): array
    {
        return collect(MatchEventType::cases())
            ->mapWithKeys(fn (MatchEventType $type): array => [$type->value => $events->where('type', $type)->count()])
            ->all();
    }
}
