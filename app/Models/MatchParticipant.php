<?php

namespace App\Models;

use App\Enums\MatchParticipantSide;
use App\Enums\MatchParticipantSourceType;
use Database\Factories\MatchParticipantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Describes where one side (home or away) of a match gets its team from,
 * instead of the match always pointing straight at a resolved team. This is
 * groundwork for knockout-style phases: a match can be created ahead of time
 * with e.g. its home side set to "the winner of match #12" or "1st place of
 * Group A", and resolved into a concrete team later once that information is
 * available. Resolving the reference is not implemented yet.
 *
 * @property int $id
 * @property int $match_id
 * @property MatchParticipantSide $side
 * @property MatchParticipantSourceType $type
 * @property int|null $team_id
 * @property int|null $source_match_id
 * @property int|null $source_phase_id
 * @property int|null $source_group_id
 * @property int|null $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['side', 'type', 'team_id', 'source_match_id', 'source_phase_id', 'source_group_id', 'position'])]
class MatchParticipant extends Model
{
    /** @use HasFactory<MatchParticipantFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'side' => MatchParticipantSide::class,
            'type' => MatchParticipantSourceType::class,
        ];
    }

    /**
     * The match this participant reference belongs to (one of its two sides).
     *
     * @return BelongsTo<TournamentMatch, $this>
     */
    public function match(): BelongsTo
    {
        return $this->belongsTo(TournamentMatch::class, 'match_id');
    }

    /**
     * Used when type is Team: the fixed team assigned to this side.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Used when type is MatchWinner: the other match whose winner feeds this side.
     *
     * @return BelongsTo<TournamentMatch, $this>
     */
    public function sourceMatch(): BelongsTo
    {
        return $this->belongsTo(TournamentMatch::class, 'source_match_id');
    }

    /**
     * Used when type is StandingPosition: the phase whose standings table to read.
     *
     * @return BelongsTo<CompetitionPhase, $this>
     */
    public function sourcePhase(): BelongsTo
    {
        return $this->belongsTo(CompetitionPhase::class, 'source_phase_id');
    }

    /**
     * Used when type is StandingPosition and the table is scoped to one group
     * instead of the whole category (null means the whole category's table).
     *
     * @return BelongsTo<Group, $this>
     */
    public function sourceGroup(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'source_group_id');
    }
}
