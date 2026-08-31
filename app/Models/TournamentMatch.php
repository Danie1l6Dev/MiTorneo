<?php

namespace App\Models;

use App\Enums\MatchParticipantSide;
use App\Enums\MatchStatus;
use Database\Factories\TournamentMatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * home_team_id/away_team_id are nullable so a match can be created before its
 * teams are known (e.g. a knockout match awaiting a previous phase's result);
 * see homeParticipant()/awayParticipant() for how such a pending side is described.
 *
 * @property int $id
 * @property int $tournament_id
 * @property int $category_id
 * @property int $competition_phase_id
 * @property int|null $group_id
 * @property int|null $league_schedule_id
 * @property int|null $home_team_id
 * @property int|null $away_team_id
 * @property int|null $home_score
 * @property int|null $away_score
 * @property MatchStatus $status
 * @property int|null $round_number
 * @property Carbon|null $scheduled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table('matches')]
#[Fillable(['group_id', 'home_team_id', 'away_team_id', 'home_score', 'away_score', 'status', 'round_number', 'scheduled_at'])]
class TournamentMatch extends Model
{
    /** @use HasFactory<TournamentMatchFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => MatchStatus::class,
            'scheduled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Tournament, $this>
     */
    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<CompetitionPhase, $this>
     */
    public function competitionPhase(): BelongsTo
    {
        return $this->belongsTo(CompetitionPhase::class);
    }

    /**
     * @return BelongsTo<Group, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * @return BelongsTo<LeagueSchedule, $this>
     */
    public function leagueSchedule(): BelongsTo
    {
        return $this->belongsTo(LeagueSchedule::class);
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function homeTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'home_team_id');
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function awayTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'away_team_id');
    }

    /**
     * All participant-source references recorded for this match (at most one per side).
     *
     * @return HasMany<MatchParticipant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(MatchParticipant::class, 'match_id');
    }

    /**
     * How the home side of this match is determined, when it isn't a fixed
     * team yet (e.g. "winner of match X" or "1st place of Group A").
     *
     * @return HasOne<MatchParticipant, $this>
     */
    public function homeParticipant(): HasOne
    {
        return $this->hasOne(MatchParticipant::class, 'match_id')->where('side', MatchParticipantSide::Home);
    }

    /**
     * How the away side of this match is determined, when it isn't a fixed
     * team yet (e.g. "winner of match X" or "1st place of Group A").
     *
     * @return HasOne<MatchParticipant, $this>
     */
    public function awayParticipant(): HasOne
    {
        return $this->hasOne(MatchParticipant::class, 'match_id')->where('side', MatchParticipantSide::Away);
    }
}
