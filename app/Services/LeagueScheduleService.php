<?php

namespace App\Services;

use App\Enums\ScheduleFormat;
use App\Models\Team;
use Illuminate\Support\Collection;

class LeagueScheduleService
{
    /**
     * Generate the rounds and fixtures for a league schedule using the circle method.
     *
     * @param  Collection<int, Team>  $teams
     * @return array<int, array{round_number: int, leg: int, resting_team_id: int|null, fixtures: array<int, array{home_team_id: int, away_team_id: int}>}>
     */
    public function generate(Collection $teams, ScheduleFormat $format): array
    {
        $teamIds = $teams->pluck('id')->values()->all();

        $firstLeg = $this->roundRobin($teamIds);

        $rounds = match ($format) {
            ScheduleFormat::SingleRound => $firstLeg,
            ScheduleFormat::HomeAndAway => [...$firstLeg, ...$this->reverseLeg($firstLeg)],
        };

        $firstLegRoundsCount = count($firstLeg);

        return array_map(fn (array $round, int $index): array => [
            'round_number' => $index + 1,
            'leg' => $index < $firstLegRoundsCount ? 1 : 2,
            'resting_team_id' => $round['resting'],
            'fixtures' => $round['pairs'],
        ], $rounds, array_keys($rounds));
    }

    /**
     * Pair up teams for every round of a single round-robin, using the circle method:
     * one team is held fixed while the rest rotate one position each round. An odd
     * number of teams gets a null "bye" slot added so one team rests each round.
     *
     * @param  array<int, int>  $teamIds
     * @return array<int, array{pairs: array<int, array{home_team_id: int, away_team_id: int}>, resting: int|null}>
     */
    private function roundRobin(array $teamIds): array
    {
        $fixture = $teamIds;

        if (count($fixture) % 2 !== 0) {
            $fixture[] = null;
        }

        $slots = count($fixture);
        $roundsCount = $slots - 1;
        $half = intdiv($slots, 2);

        $rounds = [];

        for ($round = 0; $round < $roundsCount; $round++) {
            $pairs = [];
            $resting = null;

            for ($i = 0; $i < $half; $i++) {
                $home = $fixture[$i];
                $away = $fixture[$slots - 1 - $i];

                if ($home === null || $away === null) {
                    $resting = $home ?? $away;

                    continue;
                }

                $pairs[] = ['home_team_id' => $home, 'away_team_id' => $away];
            }

            $rounds[] = ['pairs' => $pairs, 'resting' => $resting];

            $last = $fixture[$slots - 1];
            for ($i = $slots - 1; $i > 1; $i--) {
                $fixture[$i] = $fixture[$i - 1];
            }
            $fixture[1] = $last;
        }

        return $rounds;
    }

    /**
     * Build the second leg by swapping home/away for every fixture of the first leg,
     * keeping the same round order (round N+k mirrors round k of the first leg).
     *
     * @param  array<int, array{pairs: array<int, array{home_team_id: int, away_team_id: int}>, resting: int|null}>  $rounds
     * @return array<int, array{pairs: array<int, array{home_team_id: int, away_team_id: int}>, resting: int|null}>
     */
    private function reverseLeg(array $rounds): array
    {
        return array_map(fn (array $round): array => [
            'pairs' => array_map(fn (array $pair): array => [
                'home_team_id' => $pair['away_team_id'],
                'away_team_id' => $pair['home_team_id'],
            ], $round['pairs']),
            'resting' => $round['resting'],
        ], $rounds);
    }
}
