<?php

namespace App\Models;

use App\Enums\MatchEventType;
use Database\Factories\MatchEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single dated occurrence for a player or a coach within a match -- a
 * goal, an assist, a yellow card, or a red card, discriminated by $type
 * rather than split across four identically-shaped tables (nothing about one
 * type needs a field the others don't). This is the base every future match
 * statistic (goleadores, tarjetas acumuladas, sanciones) derives from --
 * never store a running total on Player, Coach or Team; always recompute
 * from these rows.
 *
 * $player_id and $coach_id are both nullable and mutually exclusive --
 * exactly one is ever set (enforced by MatchEventRequest/MatchEventBatchRequest,
 * not a DB constraint, same "nullable columns + app-level XOR" pattern
 * MatchParticipant already uses in this codebase). A goal or assist is
 * always $player_id: a coach is never the one scoring. $team_id is
 * denormalized from that player's/coach's team at creation time (never
 * user-chosen) so per-team queries don't need a join, and so a row keeps
 * describing "this team's event" even if the subject is later edited.
 *
 * @property int $id
 * @property int $match_id
 * @property int $team_id
 * @property int|null $player_id
 * @property int|null $coach_id
 * @property MatchEventType $type
 * @property int|null $minute
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['team_id', 'player_id', 'coach_id', 'type', 'minute'])]
class MatchEvent extends Model
{
    /** @use HasFactory<MatchEventFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => MatchEventType::class,
        ];
    }

    /**
     * @return BelongsTo<TournamentMatch, $this>
     */
    public function match(): BelongsTo
    {
        return $this->belongsTo(TournamentMatch::class, 'match_id');
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<Player, $this>
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /**
     * @return BelongsTo<Coach, $this>
     */
    public function coach(): BelongsTo
    {
        return $this->belongsTo(Coach::class);
    }

    /**
     * Display name for whoever this event is actually about, prefixed to
     * make a coach card visually distinct from a player's in a shared list.
     */
    public function subjectLabel(): string
    {
        if ($this->coach_id !== null) {
            return __('DT').': '.$this->coach->full_name;
        }

        return $this->player->full_name;
    }
}
