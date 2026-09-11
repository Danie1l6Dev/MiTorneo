<?php

namespace App\Models;

use App\Enums\CompetitionPhaseType;
use App\Enums\MatchEventType;
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
 * @property int|null $first_leg_match_id
 * @property int|null $group_id
 * @property int|null $referee_id
 * @property int|null $league_schedule_id
 * @property int|null $home_team_id
 * @property int|null $away_team_id
 * @property int|null $home_score
 * @property int|null $away_score
 * @property int|null $home_extra_time_score
 * @property int|null $away_extra_time_score
 * @property int|null $home_penalty_score
 * @property int|null $away_penalty_score
 * @property MatchStatus $status
 * @property int|null $round_number
 * @property Carbon|null $scheduled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table('matches')]
#[Fillable(['group_id', 'referee_id', 'home_team_id', 'away_team_id', 'home_score', 'away_score', 'home_extra_time_score', 'away_extra_time_score', 'home_penalty_score', 'away_penalty_score', 'status', 'round_number', 'scheduled_at'])]
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
     * The referee assigned to direct this match -- optional, since a match
     * can exist (and even be played) before one is recorded.
     *
     * @return BelongsTo<Referee, $this>
     */
    public function referee(): BelongsTo
    {
        return $this->belongsTo(Referee::class);
    }

    /**
     * The first leg of this match's two-legged knockout cross, when this
     * match is the second (decisive) leg -- null for a league match, a
     * single-match knockout cross, or the first leg itself.
     *
     * @return BelongsTo<TournamentMatch, $this>
     */
    public function firstLeg(): BelongsTo
    {
        return $this->belongsTo(TournamentMatch::class, 'first_leg_match_id');
    }

    /**
     * The second leg of this match's two-legged knockout cross, when this
     * match is the first leg -- null for a league match or a single-match
     * knockout cross.
     *
     * @return HasOne<TournamentMatch, $this>
     */
    public function secondLeg(): HasOne
    {
        return $this->hasOne(TournamentMatch::class, 'first_leg_match_id');
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

    /**
     * The id of the team that won this match, or null if it hasn't finished,
     * it's a genuine draw (only possible in a league phase), or -- for a
     * knockout match -- the tie hasn't been broken by extra time or
     * penalties yet. Considers, in order: the regular + extra time aggregate
     * score, then the penalty shoot-out score.
     */
    public function winnerTeamId(): ?int
    {
        if ($this->status !== MatchStatus::Finished || $this->home_score === null || $this->away_score === null) {
            return null;
        }

        $homeTotal = $this->home_score + ($this->home_extra_time_score ?? 0);
        $awayTotal = $this->away_score + ($this->away_extra_time_score ?? 0);

        if ($homeTotal !== $awayTotal) {
            return $homeTotal > $awayTotal ? $this->home_team_id : $this->away_team_id;
        }

        if ($this->home_penalty_score !== null && $this->away_penalty_score !== null && $this->home_penalty_score !== $this->away_penalty_score) {
            return $this->home_penalty_score > $this->away_penalty_score ? $this->home_team_id : $this->away_team_id;
        }

        return null;
    }

    /**
     * Whether this match is the first leg of a two-legged knockout cross --
     * i.e. its result alone doesn't decide who advances, a second leg does.
     * False for a league match, a single-match knockout cross, or the
     * second leg itself.
     */
    public function isFirstLegOfTwoLeggedTie(): bool
    {
        return $this->relationLoaded('secondLeg') ? $this->secondLeg !== null : $this->secondLeg()->exists();
    }

    /**
     * Whether this match's own result (aggregated with a first leg, if any)
     * is what decides who advances out of its knockout cross: false for a
     * league match or a first leg still awaiting its second, true for a
     * single-match knockout cross or the second (decisive) leg of a
     * two-legged one.
     */
    public function isDecisiveKnockoutLeg(): bool
    {
        return $this->competitionPhase->type !== CompetitionPhaseType::League
            && ! $this->isFirstLegOfTwoLeggedTie();
    }

    /**
     * This match's own regular-time score, aggregated with the first leg's
     * (mapped onto this leg's sides, which swap between legs -- the first
     * leg's away score is this leg's home team's other total) when this is
     * a second leg. Null while a score this needs hasn't been recorded yet.
     *
     * @return array{home: int, away: int}|null
     */
    public function regularTimeAggregate(): ?array
    {
        if ($this->home_score === null || $this->away_score === null) {
            return null;
        }

        if ($this->first_leg_match_id === null) {
            return ['home' => $this->home_score, 'away' => $this->away_score];
        }

        $firstLeg = $this->firstLeg;

        if ($firstLeg->home_score === null || $firstLeg->away_score === null) {
            return null;
        }

        return [
            'home' => $this->home_score + $firstLeg->away_score,
            'away' => $this->away_score + $firstLeg->home_score,
        ];
    }

    /**
     * The id of the team that won this match's whole knockout cross: for a
     * league match or a single-match cross, exactly winnerTeamId(); for the
     * second leg of a two-legged cross, the aggregate (regular-time) score
     * across both legs, falling back -- when that aggregate is still level
     * -- to this (decisive) leg's own extra-time/penalty tie-break, the
     * exact same fields and mechanism a single-match cross already uses (no
     * away-goals rule or other new tie-break is introduced). Null while the
     * cross isn't decided yet, including while the first leg hasn't been
     * played.
     */
    public function tieWinnerTeamId(): ?int
    {
        if ($this->first_leg_match_id === null) {
            return $this->winnerTeamId();
        }

        if ($this->status !== MatchStatus::Finished || $this->firstLeg->status !== MatchStatus::Finished) {
            return null;
        }

        $aggregate = $this->regularTimeAggregate();

        if ($aggregate === null) {
            return null;
        }

        $homeTotal = $aggregate['home'] + ($this->home_extra_time_score ?? 0);
        $awayTotal = $aggregate['away'] + ($this->away_extra_time_score ?? 0);

        if ($homeTotal !== $awayTotal) {
            return $homeTotal > $awayTotal ? $this->home_team_id : $this->away_team_id;
        }

        if ($this->home_penalty_score !== null && $this->away_penalty_score !== null && $this->home_penalty_score !== $this->away_penalty_score) {
            return $this->home_penalty_score > $this->away_penalty_score ? $this->home_team_id : $this->away_team_id;
        }

        return null;
    }

    /**
     * Every event (goal, assist, yellow/red card) recorded for this match,
     * for either team. These are a complementary, informational record --
     * they never feed back into home_score/away_score, which remain the
     * only source of truth for the result, standings, and bracket
     * progression. See goals()/assists()/yellowCards()/redCards() to scope
     * to one type.
     *
     * @return HasMany<MatchEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(MatchEvent::class, 'match_id');
    }

    /**
     * @return HasMany<MatchEvent, $this>
     */
    public function goals(): HasMany
    {
        return $this->events()->where('type', MatchEventType::Goal);
    }

    /**
     * @return HasMany<MatchEvent, $this>
     */
    public function assists(): HasMany
    {
        return $this->events()->where('type', MatchEventType::Assist);
    }

    /**
     * @return HasMany<MatchEvent, $this>
     */
    public function yellowCards(): HasMany
    {
        return $this->events()->where('type', MatchEventType::YellowCard);
    }

    /**
     * @return HasMany<MatchEvent, $this>
     */
    public function redCards(): HasMany
    {
        return $this->events()->where('type', MatchEventType::RedCard);
    }

    /**
     * Every disciplinary Sanction that originated from a card in this match.
     *
     * @return HasMany<Sanction, $this>
     */
    public function sanctions(): HasMany
    {
        return $this->hasMany(Sanction::class, 'match_id');
    }

    /**
     * True once the match is finished and either team's tally of logged goal
     * events disagrees with its final score -- purely informational, mirrors
     * the callout on the match edit screen so the discrepancy is visible from
     * calendar/bracket cards too, without needing to open the match.
     */
    public function hasGoalMismatch(): bool
    {
        if ($this->status !== MatchStatus::Finished || $this->home_score === null || $this->away_score === null) {
            return false;
        }

        $goals = $this->relationLoaded('goals') ? $this->goals : $this->goals()->get();

        return $goals->where('team_id', $this->home_team_id)->count() !== $this->home_score
            || $goals->where('team_id', $this->away_team_id)->count() !== $this->away_score;
    }

    /**
     * Whether this match had at least one expulsion (a player or coach red
     * card, straight or via a second yellow) -- purely informational. Same
     * relation-or-query fallback as hasGoalMismatch(), and deliberately not
     * gated on the match being Finished -- unlike a score mismatch, a
     * recorded expulsion is meaningful the moment it exists.
     */
    public function hasRedCard(): bool
    {
        $redCards = $this->relationLoaded('redCards') ? $this->redCards : $this->redCards()->get();

        return $redCards->isNotEmpty();
    }

    /**
     * How many red cards $teamId's side picked up in this match -- shown as
     * that many small red-card icons under the team's own name on
     * calendar/bracket cards, so an expulsion is visible (and attributed to
     * the right side) without opening the match. Almost always 0 or 1;
     * technically uncapped since nothing stops two straight reds for the
     * same team in one match.
     */
    public function redCardCountForTeam(int $teamId): int
    {
        $redCards = $this->relationLoaded('redCards') ? $this->redCards : $this->redCards()->get();

        return $redCards->where('team_id', $teamId)->count();
    }
}
