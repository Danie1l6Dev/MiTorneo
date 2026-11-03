<?php

namespace App\Services;

use App\Enums\CompetitionPhaseType;
use App\Enums\DrawMethod;
use App\Enums\MatchStatus;
use App\Enums\PairingScope;
use App\Models\CompetitionPhase;
use App\Models\Group;
use App\Models\Team;
use App\Models\TournamentMatch;
use Illuminate\Support\Collection;

/**
 * A "ronda de cruces" is a knockout-type phase made of a single round of
 * single-match crosses (see CompetitionPhase::$cross_round), used when the
 * organizer wants to cut straight from a league into one-off matches that
 * don't fit a fixed bracket: 1.º A contra el último de B, etc. Its winners,
 * the teams that passed directly (never played a cross) and the best losers
 * are drawn again into the next phase.
 *
 * This service decides who plays whom when such a phase is created from
 * standings tables, and reads its outcome back once the crosses are played.
 */
class CrossRoundService
{
    public function __construct(
        private StandingsService $standings,
        private PhaseEligibilityService $eligibility,
    ) {}

    /**
     * Who passes directly and who plays whom, given how many teams qualify
     * from each standings table and how many of those (the best ones) skip
     * the crosses. $counts and $direct are keyed by the table's position in
     * $tables.
     *
     * Seeded pairing crosses tables two at a time (the best playing team of
     * one against the worst of the other, and so on), exactly like
     * StandingsService::seedQualifiers; a table left without a partner is
     * paired against itself (best vs worst). Random pairing ignores tables
     * and just draws all the playing teams. Those two are the defaults; when
     * $scope asks for the other combination (random but only between groups,
     * or by position but ignoring groups) the playing teams go through
     * pairPool() instead, seeded by their position in their own table.
     *
     * @param  array<int, array{label: string, rows: array<int, array{team: Team}>}>  $tables
     * @param  array<int, int>  $counts
     * @param  array<int, int>  $direct
     * @return array{direct: Collection<int, Team>, pairs: array<int, array{0: Team, 1: Team}>, error: string|null}
     */
    public function plan(array $tables, array $counts, array $direct, DrawMethod $method, ?PairingScope $scope = null): array
    {
        $directTeams = collect();
        $playingByTable = [];

        foreach (array_values($tables) as $index => $table) {
            $qualifying = array_slice($table['rows'], 0, $counts[$index] ?? 0);
            $directCount = $direct[$index] ?? 0;

            foreach (array_slice($qualifying, 0, $directCount) as $row) {
                $directTeams->push($row['team']);
            }

            $playing = array_map(fn (array $row): Team => $row['team'], array_slice($qualifying, $directCount));

            if ($playing !== []) {
                $playingByTable[] = $playing;
            }
        }

        $playingCount = array_sum(array_map('count', $playingByTable));

        if ($playingCount < 2) {
            return $this->failed($directTeams, __('Se necesitan al menos 2 equipos que jueguen la ronda de cruces.'));
        }

        if ($playingCount % 2 !== 0) {
            return $this->failed($directTeams, __('Los equipos que juegan la ronda de cruces deben ser una cantidad par, y ahora son :count. Ajusta los clasificados o los que pasan directo.', ['count' => $playingCount]));
        }

        if (($method === DrawMethod::Random && $scope === PairingScope::BetweenGroups)
            || ($method === DrawMethod::Seeded && $scope === PairingScope::Anyone)) {
            $meta = [];

            foreach (array_values($tables) as $group => $table) {
                foreach (array_values($table['rows']) as $position => $row) {
                    $meta[$row['team']->id] = ['group' => $group, 'position' => $position + 1];
                }
            }

            $pooled = $this->pairPool(collect(array_merge(...$playingByTable)), $meta, $method, $scope);

            return ['direct' => $directTeams, 'pairs' => $pooled['pairs'], 'error' => $pooled['error']];
        }

        if ($method === DrawMethod::Random) {
            $pool = array_merge(...$playingByTable);
            shuffle($pool);

            return ['direct' => $directTeams, 'pairs' => array_chunk($pool, 2), 'error' => null];
        }

        $pairs = [];

        foreach (array_chunk($playingByTable, 2) as $tablePair) {
            if (count($tablePair) === 2) {
                [$first, $second] = $tablePair;

                if (count($first) !== count($second)) {
                    return $this->failed($directTeams, __('Para cruzar dos grupos por posición deben jugar la misma cantidad de equipos cada uno (ahora :first y :second). Ajusta los clasificados o los que pasan directo.', ['first' => count($first), 'second' => count($second)]));
                }

                foreach ($first as $position => $team) {
                    $pairs[] = [$team, $second[count($second) - 1 - $position]];
                }

                continue;
            }

            $lone = $tablePair[0];
            $count = count($lone);

            for ($position = 0; $position < intdiv($count, 2); $position++) {
                $pairs[] = [$lone[$position], $lone[$count - 1 - $position]];
            }
        }

        return ['direct' => $directTeams, 'pairs' => $pairs, 'error' => null];
    }

    /**
     * Group (the position of its table) and position inside it that every
     * team had in the league this cross round chain started from, keyed by
     * team id: what a draw needs to cross teams "entre grupos" or "por
     * posición" once only winners and best losers remain. Empty when the
     * chain doesn't start from a league.
     *
     * @return array<int, array{group: int, position: int}>
     */
    public function seedingMeta(CompetitionPhase $phase): array
    {
        $league = $phase;

        while ($league !== null && $league->type !== CompetitionPhaseType::League) {
            $league = $this->eligibility->previousPhase($league);
        }

        if ($league === null) {
            return [];
        }

        $meta = [];

        foreach (array_values($this->standings->tablesForPhase($league)) as $group => $table) {
            foreach (array_values($table['rows']) as $position => $row) {
                $meta[$row['team']->id] = ['group' => $group, 'position' => $position + 1];
            }
        }

        return $meta;
    }

    /**
     * Pair every team of $teams with one other, whatever comes next: a cross
     * round's single matches or the first round of a bracket.
     *
     * - Random + Anyone: a plain draw.
     * - Random + BetweenGroups: a draw where nobody meets a team of their own
     *   group.
     * - Seeded + Anyone: the best-positioned team against the worst, then the
     *   second best against the second worst... ignoring groups (ties on
     *   position are ordered by group).
     * - Seeded + BetweenGroups: the best-positioned team left against the
     *   worst-positioned one left from ANOTHER group, repeatedly.
     *
     * $meta tells each team's group and position (see seedingMeta()); a team
     * missing from it is ranked last and has no group.
     *
     * @param  Collection<int, Team>  $teams
     * @param  array<int, array{group: int, position: int}>  $meta
     * @return array{pairs: array<int, array{0: Team, 1: Team}>, error: string|null}
     */
    public function pairPool(Collection $teams, array $meta, DrawMethod $method, PairingScope $scope): array
    {
        $pool = $teams->values()->all();

        if (count($pool) < 2 || count($pool) % 2 !== 0) {
            return ['pairs' => [], 'error' => __('Se necesita una cantidad par de equipos (al menos 2) para sortear los cruces, y ahora son :count.', ['count' => count($pool)])];
        }

        $groupOf = fn (Team $team): ?int => $meta[$team->id]['group'] ?? null;
        $positionOf = fn (Team $team): int => $meta[$team->id]['position'] ?? PHP_INT_MAX;

        if ($scope === PairingScope::BetweenGroups) {
            $groups = array_unique(array_map($groupOf, $pool));

            if (in_array(null, $groups, true) || count($groups) < 2) {
                return ['pairs' => [], 'error' => __('No se pueden cruzar los equipos entre grupos: no vienen de al menos dos grupos distintos.')];
            }
        }

        $failed = ['pairs' => [], 'error' => __('No se pueden armar los cruces entre grupos con estos equipos: no hay forma de que nadie se enfrente a uno de su propio grupo.')];

        if ($method === DrawMethod::Random) {
            shuffle($pool);

            if ($scope === PairingScope::Anyone) {
                return ['pairs' => array_chunk($pool, 2), 'error' => null];
            }

            $pairs = $this->pairAcrossGroups($pool, $groupOf);

            return $pairs === null ? $failed : ['pairs' => $pairs, 'error' => null];
        }

        usort($pool, fn (Team $a, Team $b): int => [$positionOf($a), $groupOf($a) ?? 0, $a->id] <=> [$positionOf($b), $groupOf($b) ?? 0, $b->id]);

        $pairs = [];

        if ($scope === PairingScope::Anyone) {
            $count = count($pool);

            for ($i = 0; $i < intdiv($count, 2); $i++) {
                $pairs[] = [$pool[$i], $pool[$count - 1 - $i]];
            }

            return ['pairs' => $pairs, 'error' => null];
        }

        while ($pool !== []) {
            $best = array_shift($pool);
            $opponentIndex = null;

            for ($i = count($pool) - 1; $i >= 0; $i--) {
                if ($groupOf($pool[$i]) !== $groupOf($best)) {
                    $opponentIndex = $i;

                    break;
                }
            }

            if ($opponentIndex === null) {
                return $failed;
            }

            $pairs[] = [$best, $pool[$opponentIndex]];
            array_splice($pool, $opponentIndex, 1);
        }

        return ['pairs' => $pairs, 'error' => null];
    }

    /**
     * A random pairing of an already shuffled $pool where nobody meets a team
     * of their own group, found by backtracking; null when none exists.
     *
     * @param  array<int, Team>  $pool
     * @param  callable(Team): ?int  $groupOf
     * @return array<int, array{0: Team, 1: Team}>|null
     */
    private function pairAcrossGroups(array $pool, callable $groupOf): ?array
    {
        if ($pool === []) {
            return [];
        }

        $first = array_shift($pool);

        foreach ($pool as $index => $candidate) {
            if ($groupOf($candidate) === $groupOf($first)) {
                continue;
            }

            $rest = $pool;
            unset($rest[$index]);

            $pairs = $this->pairAcrossGroups(array_values($rest), $groupOf);

            if ($pairs !== null) {
                return [[$first, $candidate], ...$pairs];
            }
        }

        return null;
    }

    /**
     * The cross round laid out for display, one column per group: the teams
     * of that group that passed directly and the crosses played between two
     * of its teams. A cross between teams of different groups (or of no
     * group) goes into 'between' instead. A category without groups gets a
     * single unlabeled column.
     *
     * @return array{columns: array<int, array{label: string|null, direct: Collection<int, Team>, matches: Collection<int, TournamentMatch>}>, between: Collection<int, TournamentMatch>}
     */
    public function columns(CompetitionPhase $phase): array
    {
        $tournament = $phase->tournament;

        $matches = $phase->matches()
            ->with(['homeTeam', 'awayTeam', 'venue', 'goals', 'redCards'])
            ->orderBy('id')
            ->get();

        $playingIds = $matches->flatMap(fn (TournamentMatch $match): array => [$match->home_team_id, $match->away_team_id]);
        $direct = $phase->teams()->get()->reject(fn (Team $team): bool => $playingIds->contains($team->id));

        // Group of every team involved, looked up once each.
        $groups = [];
        $groupOf = function (?Team $team) use (&$groups, $tournament): ?Group {
            if ($team === null) {
                return null;
            }

            if (! array_key_exists($team->id, $groups)) {
                // A legacy plantel carries its group on itself; a global one is
                // in tournament_team.
                $groupId = $team->tournamentGroupId() ?? $team->groupIn($tournament)?->id;
                $groups[$team->id] = $groupId ? Group::query()->find($groupId) : null;
            }

            return $groups[$team->id];
        };

        $columns = [];
        $between = collect();

        $column = function (?Group $group) use (&$columns): void {
            $key = $group?->id ?? 0;
            $columns[$key] ??= ['order' => $group?->order ?? 0, 'label' => $group?->name, 'direct' => collect(), 'matches' => collect()];
        };

        foreach ($direct as $team) {
            $group = $groupOf($team);
            $column($group);
            $columns[$group?->id ?? 0]['direct']->push($team);
        }

        foreach ($matches as $match) {
            $home = $groupOf($match->homeTeam);
            $away = $groupOf($match->awayTeam);

            if ($home === null || $away === null || $home->id !== $away->id) {
                $between->push($match);

                continue;
            }

            $column($home);
            $columns[$home->id]['matches']->push($match);
        }

        $ordered = collect($columns)->sortBy([['order', 'asc']])->values()->map(fn (array $column): array => [
            'label' => $column['label'],
            'direct' => $column['direct']->values(),
            'matches' => $column['matches']->values(),
        ])->all();

        return ['columns' => $ordered, 'between' => $between];
    }

    /**
     * What a played cross round produced: the teams that passed directly,
     * each cross's winner, and the losers ranked best first (smallest defeat,
     * then most goals scored, then fair play (fewer card penalty points in
     * that cross) -- a cross decided on penalties after a draw counts as a
     * level result, so its loser ranks first). Anything still
     * without a winner is counted in 'undecided'.
     *
     * @return array{direct: Collection<int, Team>, winners: Collection<int, Team>, losers: array<int, array{team: Team, goal_difference: int, goals_for: int, fair_play: int}>, undecided: int}
     */
    public function outcome(CompetitionPhase $phase): array
    {
        $matches = $phase->matches()->with(['homeTeam', 'awayTeam'])
            ->where('status', '!=', MatchStatus::Cancelled)
            ->orderBy('id')
            ->get();

        $playingIds = $matches->flatMap(fn (TournamentMatch $match): array => [$match->home_team_id, $match->away_team_id]);

        $direct = $phase->teams()->get()->reject(fn (Team $team): bool => $playingIds->contains($team->id))->values();

        $winners = collect();
        $losers = [];
        $undecided = 0;
        $fairPlay = app(FairPlayService::class)->penaltiesByTeam($matches);

        foreach ($matches as $match) {
            $winnerId = $match->tieWinnerTeamId();

            if ($winnerId === null) {
                $undecided++;

                continue;
            }

            $winnerIsHome = $winnerId === $match->home_team_id;
            $winners->push($winnerIsHome ? $match->homeTeam : $match->awayTeam);

            $loserGoals = (int) ($winnerIsHome ? $match->away_score : $match->home_score);
            $winnerGoals = (int) ($winnerIsHome ? $match->home_score : $match->away_score);

            $losers[] = [
                'team' => $winnerIsHome ? $match->awayTeam : $match->homeTeam,
                'goal_difference' => min(0, $loserGoals - $winnerGoals),
                'goals_for' => $loserGoals,
                'fair_play' => $fairPlay[($winnerIsHome ? $match->away_team_id : $match->home_team_id)] ?? 0,
            ];
        }

        usort($losers, fn (array $a, array $b): int => [$b['goal_difference'], $b['goals_for'], $a['fair_play']] <=> [$a['goal_difference'], $a['goals_for'], $b['fair_play']]);

        return ['direct' => $direct, 'winners' => $winners->values(), 'losers' => $losers, 'undecided' => $undecided];
    }

    /**
     * @param  Collection<int, Team>  $direct
     * @return array{direct: Collection<int, Team>, pairs: array<int, array{0: Team, 1: Team}>, error: string}
     */
    private function failed(Collection $direct, string $error): array
    {
        return ['direct' => $direct, 'pairs' => [], 'error' => $error];
    }
}
