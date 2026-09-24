<?php

namespace App\Services;

use App\Enums\CompetitionPhaseType;
use App\Enums\MatchEventType;
use App\Enums\MatchStatus;
use App\Enums\StatisticsPhaseScope;
use App\Models\Category;
use App\Models\Coach;
use App\Models\CompetitionPhase;
use App\Models\Group;
use App\Models\MatchEvent;
use App\Models\Player;
use App\Models\Team;
use App\Models\Tournament;
use Illuminate\Support\Collection;

class CompetitionStatisticsService
{
    /**
     * A category's player leaderboard for one event type (goals, assists,
     * yellow cards, red cards), counted straight from match_events -- never
     * from a stored total. Every row is a single player at a single team, so
     * two players sharing a name still appear as separate rows.
     *
     * $group scopes to that group's teams only, by each team's own group_id
     * (its category-group membership), never a phase's temporary roster --
     * a team keeps counting toward its own group here even in a later phase
     * that mixes groups. Leave $group null for every team in the category.
     *
     * Only Finished matches contribute (the same rule StandingsService uses),
     * so a scheduled/postponed/cancelled match's events -- which shouldn't
     * exist yet in practice, since events are only ever registered from the
     * match edit screen's result form -- can never inflate a count.
     *
     * @return array<int, array{rank: int, player: Player, count: int}>
     */
    public function leaderboard(
        Category $category,
        MatchEventType $type,
        ?Group $group,
        StatisticsPhaseScope $phaseScope,
        int $limit = 50,
    ): array {
        $counts = MatchEvent::query()
            ->select('player_id')
            ->selectRaw('count(*) as aggregate_count')
            ->where('type', $type)
            ->whereNotNull('player_id')
            ->whereHas('match', function ($query) use ($category, $phaseScope) {
                $query->where('category_id', $category->id)
                    ->where('status', MatchStatus::Finished);

                if ($phaseScope === StatisticsPhaseScope::League) {
                    $query->whereHas(
                        'competitionPhase',
                        fn ($phaseQuery) => $phaseQuery->where('type', CompetitionPhaseType::League)
                    );
                }
            })
            ->when(
                $group !== null,
                fn ($query) => $query->whereHas('team', fn ($teamQuery) => $teamQuery->where('group_id', $group->id))
            )
            ->groupBy('player_id')
            ->pluck('aggregate_count', 'player_id');

        if ($counts->isEmpty()) {
            return [];
        }

        $players = Player::query()
            ->with(['team.group'])
            ->whereIn('id', $counts->keys())
            ->get()
            ->keyBy('id');

        $rows = $counts->map(fn (int $count, int $playerId): array => [
            'player' => $players[$playerId],
            'count' => $count,
        ])->values();

        // Stable, non-invented tiebreak: count descending, then player name
        // ascending -- no secondary "sporting" criteria (fewer cards, minutes
        // played, ...) that nothing in this system tracks yet.
        $sorted = $rows->sort(function (array $a, array $b): int {
            return ($b['count'] <=> $a['count']) ?: ($a['player']->full_name <=> $b['player']->full_name);
        })->values();

        return $sorted->take($limit)->values()->map(fn (array $row, int $index): array => [
            'rank' => $index + 1,
            'player' => $row['player'],
            'count' => $row['count'],
        ])->all();
    }

    /**
     * The same kind of leaderboard, for the statistics PDF: every phase of
     * $category in $tournament as its own column, plus the total -- one row
     * per player (or, for cards, also per DT) per team they played for in
     * this category, since a play-up player's events belong to the side
     * they played for, not their own roster. Scoped to this tournament too,
     * never mixing another tournament the same catalog category plays in.
     * Only Finished matches count, same as leaderboard(). Sorted by total
     * descending, then name.
     *
     * @return array{phases: Collection<int, CompetitionPhase>, rows: list<array{rank: int, name: string, team: Team, counts: array<int, int>, total: int}>}
     */
    public function phaseBreakdown(Tournament $tournament, Category $category, MatchEventType $type): array
    {
        $phases = $tournament->competitionPhases()
            ->where('category_id', $category->id)
            ->orderBy('order')
            ->orderBy('id')
            ->get();

        $counts = MatchEvent::query()
            ->join('matches', 'matches.id', '=', 'match_events.match_id')
            ->where('match_events.type', $type)
            ->where('matches.tournament_id', $tournament->id)
            ->where('matches.category_id', $category->id)
            ->where('matches.status', MatchStatus::Finished)
            // A DT can't score or assist, so only the card tables list them.
            ->when(
                in_array($type, [MatchEventType::Goal, MatchEventType::Assist], true),
                fn ($query) => $query->whereNotNull('match_events.player_id')
            )
            ->groupBy('match_events.player_id', 'match_events.coach_id', 'match_events.team_id', 'matches.competition_phase_id')
            ->select('match_events.player_id', 'match_events.coach_id', 'match_events.team_id', 'matches.competition_phase_id')
            ->selectRaw('count(*) as aggregate_count')
            ->toBase()
            ->get();

        if ($counts->isEmpty()) {
            return ['phases' => $phases, 'rows' => []];
        }

        $players = Player::query()->whereIn('id', $counts->pluck('player_id')->filter()->unique())->get()->keyBy('id');
        $coaches = Coach::query()->whereIn('id', $counts->pluck('coach_id')->filter()->unique())->get()->keyBy('id');
        $teams = Team::query()->with('group')->whereIn('id', $counts->pluck('team_id')->unique())->get()->keyBy('id');

        $rows = $counts
            ->groupBy(fn (object $row): string => ($row->player_id !== null ? "player:{$row->player_id}" : "coach:{$row->coach_id}").":{$row->team_id}")
            ->map(function (Collection $subjectRows) use ($players, $coaches, $teams): array {
                $first = $subjectRows->first();
                $counts = $subjectRows->mapWithKeys(fn (object $row): array => [(int) $row->competition_phase_id => (int) $row->aggregate_count])->all();

                return [
                    'name' => $first->player_id !== null
                        ? $players[$first->player_id]->full_name
                        : __('DT').': '.$coaches[$first->coach_id]->full_name,
                    'team' => $teams[$first->team_id],
                    'counts' => $counts,
                    'total' => array_sum($counts),
                ];
            })
            ->sort(fn (array $a, array $b): int => ($b['total'] <=> $a['total']) ?: ($a['name'] <=> $b['name']))
            ->values()
            ->map(fn (array $row, int $index): array => ['rank' => $index + 1, ...$row])
            ->all();

        return ['phases' => $phases, 'rows' => $rows];
    }
}
