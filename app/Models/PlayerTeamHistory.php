<?php

namespace App\Models;

use App\Enums\RosterEndReason;
use App\Enums\RosterStartReason;
use Database\Factories\PlayerTeamHistoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One stay of a player on a plantel: when it started and ended and why. Written
 * only by PlayerRosterService, in the same transaction that changes the roster
 * itself, so this can't drift from players.team_id / player_team.
 *
 * @property int $id
 * @property int $player_id
 * @property int|null $team_id Null once the plantel was deleted -- the names below remain.
 * @property int|null $club_id
 * @property string|null $club_name
 * @property string $team_name
 * @property string|null $category_name
 * @property string|null $group_name
 * @property int|null $jersey_number
 * @property Carbon $started_on
 * @property Carbon|null $ended_on Null while the player is still on the plantel.
 * @property RosterStartReason $start_reason
 * @property RosterEndReason|null $end_reason
 * @property bool $is_estimated Rebuilt after the fact (events/sanctions), not recorded as it happened.
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table('player_team_history')]
#[Fillable(['player_id', 'team_id', 'club_id', 'club_name', 'team_name', 'category_name', 'group_name', 'jersey_number', 'started_on', 'ended_on', 'start_reason', 'end_reason', 'is_estimated', 'notes'])]
class PlayerTeamHistory extends Model
{
    /** @use HasFactory<PlayerTeamHistoryFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'started_on' => 'date',
            'ended_on' => 'date',
            'start_reason' => RosterStartReason::class,
            'end_reason' => RosterEndReason::class,
            'is_estimated' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Player, $this>
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @param  Builder<PlayerTeamHistory>  $query
     * @return Builder<PlayerTeamHistory>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('ended_on');
    }

    public function isOpen(): bool
    {
        return $this->ended_on === null;
    }

    /**
     * "CLUB" if the stint has a club, else the plantel's own name -- the same
     * fallback the rest of the app uses for a plantel without a club.
     */
    public function clubLabel(): string
    {
        return $this->club_name ?? $this->team_name;
    }
}
