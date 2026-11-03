<?php

namespace App\Services;

use App\Models\CompetitionPhase;
use App\Models\TiebreakDraw;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The last tie-break: a "sorteo" among teams still level after every other
 * criterion. Standings and rankings are recalculated every time they are
 * opened, so a draw is stored (TiebreakDraw) and applied from there.
 *
 * A stored draw only counts while the teams it covered are EXACTLY the teams
 * that are still totally tied: if a later result changes who is tied, the old
 * draw is ignored and a new one is asked for.
 */
class TiebreakDrawService
{
    /**
     * Mark every group of consecutive, totally tied rows of $ordered, and put
     * the ones already drawn in their drawn order.
     *
     * Each tied row gets 'tie' => 'pending' (no valid draw yet) or 'drawn',
     * plus 'tie_teams' (the ids of everyone in that tie) and, when drawn,
     * 'draw_group'. Rows that are not tied (or are $skip'ped, e.g. an
     * expelled team) get 'tie' => null.
     *
     * @template T of array<string, mixed>
     *
     * @param  array<int, T>  $ordered  rows already in their final criteria order
     * @param  callable(T): string  $tieKey  consecutive rows with the same key are totally tied
     * @param  callable(T): int  $teamId
     * @param  array<int, array{group: string, positions: array<int, int>}>  $batches
     * @param  (callable(T): bool)|null  $skip
     * @return array<int, T>
     */
    public function settle(array $ordered, callable $tieKey, callable $teamId, array $batches, ?callable $skip = null): array
    {
        $result = [];
        $count = count($ordered);
        $i = 0;

        while ($i < $count) {
            $j = $i;

            while ($j + 1 < $count && $tieKey($ordered[$j + 1]) === $tieKey($ordered[$i])) {
                $j++;
            }

            $group = array_slice($ordered, $i, $j - $i + 1);
            $tied = count($group) > 1 && ($skip === null || ! $skip($group[0]));

            if (! $tied) {
                foreach ($group as $row) {
                    $result[] = [...$row, 'tie' => null, 'tie_teams' => [], 'draw_group' => null];
                }
            } else {
                $ids = array_map($teamId, $group);
                $batch = $this->batchCovering($ids, $batches);

                if ($batch !== null) {
                    usort($group, fn (array $a, array $b): int => $batch['positions'][$teamId($a)] <=> $batch['positions'][$teamId($b)]);
                }

                foreach ($group as $row) {
                    $result[] = [...$row, 'tie' => $batch !== null ? 'drawn' : 'pending', 'tie_teams' => $ids, 'draw_group' => $batch['group'] ?? null];
                }
            }

            $i = $j + 1;
        }

        return $result;
    }

    /**
     * The sets of team ids that are totally tied right now with no valid draw
     * yet, for a league phase (read off its standings tables) or a ronda de
     * cruces (read off the ranking of its losers).
     *
     * @return array<int, array<int, int>>
     */
    public function pendingTies(CompetitionPhase $phase): array
    {
        $rows = $phase->cross_round
            ? app(CrossRoundService::class)->outcome($phase)['losers']
            : collect(app(StandingsService::class)->tablesForPhase($phase))->flatMap(fn (array $table): array => $table['rows'])->all();

        $ties = [];

        foreach ($rows as $row) {
            if (($row['tie'] ?? null) === 'pending') {
                $ties[implode('-', $row['tie_teams'])] = $row['tie_teams'];
            }
        }

        return array_values($ties);
    }

    /**
     * Draw the order of $teamIds, which must be exactly one of the phase's
     * pending ties. Returns false (and stores nothing) when it is not.
     *
     * @param  array<int, int>  $teamIds
     */
    public function draw(CompetitionPhase $phase, array $teamIds): bool
    {
        $teamIds = array_values(array_unique(array_map('intval', $teamIds)));
        sort($teamIds);

        $isPending = collect($this->pendingTies($phase))->contains(function (array $tie) use ($teamIds): bool {
            sort($tie);

            return $tie === $teamIds;
        });

        if (! $isPending) {
            return false;
        }

        $order = $teamIds;
        shuffle($order);
        $group = (string) Str::uuid();

        DB::transaction(function () use ($phase, $order, $group): void {
            // Draws left over from an earlier, different tie of these teams.
            TiebreakDraw::query()->where('competition_phase_id', $phase->id)->whereIn('team_id', $order)->delete();

            foreach ($order as $index => $teamId) {
                TiebreakDraw::query()->create([
                    'competition_phase_id' => $phase->id,
                    'team_id' => $teamId,
                    'draw_group' => $group,
                    'position' => $index + 1,
                ]);
            }
        });

        return true;
    }

    /**
     * Cancel one stored draw, so its tie asks for a new one.
     */
    public function annul(CompetitionPhase $phase, string $drawGroup): int
    {
        return TiebreakDraw::query()
            ->where('competition_phase_id', $phase->id)
            ->where('draw_group', $drawGroup)
            ->delete();
    }

    /**
     * Whether, in a ranking already ordered, a tie that is still pending
     * straddles the line after the first $line rows -- i.e. the draw would
     * decide who is in and who is out. Returns the names of the tied teams,
     * or null.
     *
     * @param  array<int, array{team: \App\Models\Team, tie?: string|null, tie_teams?: array<int, int>}>  $rows
     * @return array<int, string>|null
     */
    public function pendingTieOnLine(array $rows, int $line): ?array
    {
        if ($line < 1 || $line >= count($rows)) {
            return null;
        }

        $above = $rows[$line - 1];
        $below = $rows[$line];

        if (($above['tie'] ?? null) !== 'pending' || ($below['tie'] ?? null) !== 'pending') {
            return null;
        }

        if (($above['tie_teams'] ?? []) !== ($below['tie_teams'] ?? [])) {
            return null;
        }

        return collect($rows)
            ->filter(fn (array $row): bool => in_array($row['team']->id, $above['tie_teams'], true))
            ->map(fn (array $row): string => $row['team']->name)
            ->values()
            ->all();
    }

    /**
     * The validation message for a pending tie on the line, naming the teams.
     *
     * @param  array<int, string>  $names
     */
    public function lineMessage(string $where, array $names): string
    {
        return __('En :where hay un empate total entre :teams justo en la línea de clasificación. Realiza el sorteo antes de avanzar.', [
            'where' => $where,
            'teams' => implode(', ', $names),
        ]);
    }

    /**
     * @param  array<int, int>  $ids
     * @param  array<int, array{group: string, positions: array<int, int>}>  $batches
     * @return array{group: string, positions: array<int, int>}|null
     */
    private function batchCovering(array $ids, array $batches): ?array
    {
        $sorted = $ids;
        sort($sorted);

        foreach ($batches as $batch) {
            $batchIds = array_keys($batch['positions']);
            sort($batchIds);

            if ($batchIds === $sorted) {
                return $batch;
            }
        }

        return null;
    }
}
