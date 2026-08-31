<?php

namespace App\Services;

use App\Enums\MatchStatus;
use App\Models\CompetitionPhase;
use App\Models\Group;
use App\Models\Team;
use App\Models\TournamentMatch;
use Illuminate\Support\Collection;

class StandingsService
{
    /**
     * Calculate the standings table(s) for a phase: a single table for its own
     * roster of teams when it has one (a phase created from a qualification
     * cutoff), one table per group when the category uses groups, or a single
     * table for the whole category otherwise. The table is always derived
     * fresh from the phase's finished matches.
     *
     * @return array<int, array{label: string, rows: array<int, array{team: Team, played: int, won: int, drawn: int, lost: int, goals_for: int, goals_against: int, goal_difference: int, points: int}>}>
     */
    public function tablesForPhase(CompetitionPhase $phase): array
    {
        $category = $phase->category;
        $matches = $phase->matches;
        $roster = $phase->teams;

        if ($roster->isNotEmpty()) {
            return [[
                'label' => $phase->name,
                'rows' => $this->calculate($roster, $matches),
            ]];
        }

        if ($category->uses_groups) {
            return $category->groups->map(fn (Group $group): array => [
                'label' => $group->name,
                'rows' => $this->calculate($group->teams, $matches->where('group_id', $group->id)),
            ])->values()->all();
        }

        return [[
            'label' => $category->name,
            'rows' => $this->calculate($category->teams, $matches),
        ]];
    }

    /**
     * Take the top N teams from each table (by its current order) and flatten
     * them into a single pool, e.g. for a knockout draw or a follow-up phase's
     * roster. Nothing is resolved beyond picking rows off the already-ordered
     * tables: sorting/tie-breaking is entirely calculate()'s responsibility.
     *
     * @param  array<int, array{label: string, rows: array<int, array{team: Team, played: int, won: int, drawn: int, lost: int, goals_for: int, goals_against: int, goal_difference: int, points: int}>}>  $tables
     * @return Collection<int, Team>
     */
    public function topQualifiers(array $tables, int $perTable): Collection
    {
        $teams = [];

        foreach ($tables as $table) {
            foreach (array_slice($table['rows'], 0, $perTable) as $row) {
                $teams[] = $row['team'];
            }
        }

        return collect($teams);
    }

    /**
     * Calculate a standings table from a set of teams and matches, counting only
     * finished matches with a recorded score. Nothing is persisted: the table is
     * always derived fresh from the current state of the matches.
     *
     * @param  Collection<int, Team>  $teams
     * @param  Collection<int, TournamentMatch>  $matches
     * @return array<int, array{team: Team, played: int, won: int, drawn: int, lost: int, goals_for: int, goals_against: int, goal_difference: int, points: int}>
     */
    public function calculate(Collection $teams, Collection $matches): array
    {
        /** @var array<int, Team> $teamsById */
        $teamsById = $teams->keyBy(fn (Team $team): int => $team->id)->all();

        /** @var array<int, array{played: int, won: int, drawn: int, lost: int, goals_for: int, goals_against: int, points: int}> $stats */
        $stats = [];

        foreach ($teamsById as $id => $team) {
            $stats[$id] = ['played' => 0, 'won' => 0, 'drawn' => 0, 'lost' => 0, 'goals_for' => 0, 'goals_against' => 0, 'points' => 0];
        }

        foreach ($matches as $match) {
            if ($match->status !== MatchStatus::Finished) {
                continue;
            }

            if ($match->home_score === null || $match->away_score === null) {
                continue;
            }

            $homeId = $match->home_team_id;
            $awayId = $match->away_team_id;

            if (! isset($stats[$homeId], $stats[$awayId])) {
                continue;
            }

            $home = $stats[$homeId];
            $away = $stats[$awayId];

            $home['played']++;
            $away['played']++;
            $home['goals_for'] += $match->home_score;
            $home['goals_against'] += $match->away_score;
            $away['goals_for'] += $match->away_score;
            $away['goals_against'] += $match->home_score;

            if ($match->home_score > $match->away_score) {
                $home['won']++;
                $home['points'] += 3;
                $away['lost']++;
            } elseif ($match->home_score < $match->away_score) {
                $away['won']++;
                $away['points'] += 3;
                $home['lost']++;
            } else {
                $home['drawn']++;
                $home['points']++;
                $away['drawn']++;
                $away['points']++;
            }

            $stats[$homeId] = $home;
            $stats[$awayId] = $away;
        }

        $rows = [];

        foreach ($teamsById as $id => $team) {
            $row = $stats[$id];

            $rows[] = [
                'team' => $team,
                'played' => $row['played'],
                'won' => $row['won'],
                'drawn' => $row['drawn'],
                'lost' => $row['lost'],
                'goals_for' => $row['goals_for'],
                'goals_against' => $row['goals_against'],
                'goal_difference' => $row['goals_for'] - $row['goals_against'],
                'points' => $row['points'],
            ];
        }

        usort($rows, fn (array $a, array $b): int => [$b['points'], $b['goal_difference'], $b['goals_for']]
            <=> [$a['points'], $a['goal_difference'], $a['goals_for']]);

        return $rows;
    }
}
