<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One team's place in a stored "sorteo" (see the migration). Rows sharing a
 * `draw_group` are one draw among exactly those teams.
 *
 * @property int $id
 * @property int $competition_phase_id
 * @property int $team_id
 * @property string $draw_group
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['competition_phase_id', 'team_id', 'draw_group', 'position'])]
class TiebreakDraw extends Model
{
    /**
     * @return BelongsTo<CompetitionPhase, $this>
     */
    public function competitionPhase(): BelongsTo
    {
        return $this->belongsTo(CompetitionPhase::class);
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Every draw stored for $phase, as the list StandingsService reads: each
     * entry has the draw's id and the position every team in it got.
     *
     * @return array<int, array{group: string, positions: array<int, int>}>
     */
    public static function batchesFor(CompetitionPhase|int $phase): array
    {
        $phaseId = $phase instanceof CompetitionPhase ? $phase->id : $phase;

        return static::query()
            ->where('competition_phase_id', $phaseId)
            ->get(['draw_group', 'team_id', 'position'])
            ->groupBy('draw_group')
            ->map(fn ($rows, string $group): array => [
                'group' => $group,
                'positions' => $rows->pluck('position', 'team_id')->map(fn ($position): int => (int) $position)->all(),
            ])
            ->values()
            ->all();
    }
}
