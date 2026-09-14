<?php

namespace App\Models;

use Database\Factories\MatchLineupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One player "convocado" (called up) to play for one side of one specific
 * match -- independent of Player::$team_id or the player_team pivot, since a
 * player playing UP from a younger category's roster plays here for the
 * OLDER team's side, not their own. This is what actually gates which
 * players get quick-add event buttons on the match edit page (see
 * MatchEventController and Team::clubPlayersEligibleForLineup() for how a
 * player becomes a candidate to add in the first place), and what a
 * MatchEvent's team_id resolves through for a player instead of
 * Player::$team_id directly -- see TournamentMatch::lineupTeamIdFor().
 *
 * @property int $id
 * @property int $match_id
 * @property int $team_id
 * @property int $player_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['match_id', 'team_id', 'player_id'])]
class MatchLineup extends Model
{
    /** @use HasFactory<MatchLineupFactory> */
    use HasFactory;

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
}
