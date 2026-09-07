<?php

namespace App\Services;

use App\Enums\CompetitionPhaseType;
use App\Enums\MatchEventType;
use App\Enums\MatchStatus;
use App\Enums\StatisticsPhaseScope;
use App\Models\Category;
use App\Models\Group;
use App\Models\MatchEvent;
use App\Models\Player;

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
}
